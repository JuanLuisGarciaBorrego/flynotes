<?php

declare(strict_types=1);

namespace App\Manager;

use App\Ai\Tool\PublishSkyReport;
use App\Entity\AgentMission;
use App\Enum\AgentMissionStepType;
use App\EventListener\ToolApprovalListener;
use App\Exception\AiUnavailableException;
use App\Exception\MissionDecisionConflictException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * A Sky Pulse mission, in three independent HTTP requests:
 *
 * launch()      The agent chooses a research tool. ToolApprovalListener stops it before it runs.
 * decideTool()  A human authorizes it: the tool runs and the same conversation goes on until the agent asks to publish.
 * decide()      A human approves or rejects the publication.
 *
 * The conversation travels between requests inside the mission, as a normalized MessageBag.
 */
final readonly class AgentMissionManager
{
    private const string PROMPT = 'Explore FlyNotes: autonomously choose the one tool that best helps you investigate something interesting. Request exactly one tool: a human must authorize it before it runs.';
    private const string NOT_EXECUTED = 'Not executed: only one tool can run per mission.';
    private const string MALFORMED_ARGUMENTS = 'Your previous tool call had arguments that were not valid JSON. Request it again with valid JSON arguments.';

    public function __construct(
        #[Autowire(service: 'ai.agent.sky_pulse')]
        private AgentInterface $agent,
        #[Autowire(service: 'ai.toolbox.sky_pulse')]
        private ToolboxInterface $toolbox,
        private SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private PaperPlaneManager $paperPlaneManager,
        private ParticipantManager $participantManager,
        private ToolApprovalListener $toolApproval,
        #[Autowire(env: 'bool:AI_ENABLED')]
        private bool $aiEnabled,
    ) {
    }

    /** @throws AiUnavailableException when AI is disabled: no mission is created */
    public function launch(): AgentMission
    {
        if (!$this->aiEnabled) {
            throw new AiUnavailableException();
        }

        $mission = new AgentMission();
        $mission->addStep(AgentMissionStepType::Started);

        $this->entityManager->persist($mission);
        $this->entityManager->flush();

        try {
            $execution = $this->run(new MessageBag(Message::ofUser(self::PROMPT)), ['tools' => ToolApprovalListener::RESEARCH_TOOLS, 'tool_choice' => 'required'], $mission);

            // One tool per mission: the first one requested.
            $toolCalls = $execution->getPendingToolCalls();
            $selected = array_shift($toolCalls) ?? throw new \UnexpectedValueException('Sky Pulse finished without requesting a tool.');

            foreach ($toolCalls as $other) {
                $mission->addStep(AgentMissionStepType::Rejected, $other->getName());
            }

            // What follows the model's tool calls is provisional: the real results are added on approval.
            $messages = $execution->getMessages()->getMessages();
            $end = array_find_key($messages, static fn ($message): bool => $message instanceof AssistantMessage && $message->hasToolCalls());

            $mission->requestToolApproval(
                $selected->getId(),
                $selected->getName(),
                $selected->getArguments(),
                $this->serializer->normalize(new MessageBag(...\array_slice($messages, 0, $end + 1))),
            );
        } catch (\Throwable $exception) {
            $this->fail($mission, $exception);
        }

        $this->entityManager->flush();

        return $mission;
    }

    /**
     * @throws MissionDecisionConflictException when another human decided first
     * @throws AiUnavailableException           when AI is disabled and approving would call the model: the mission stays waiting, and can still be rejected
     */
    public function decideTool(AgentMission $mission, bool $accepted): AgentMission
    {
        if ($accepted && !$this->aiEnabled) {
            throw new AiUnavailableException();
        }

        // The decision is committed before the tool and the model run, so no lock is held meanwhile.
        $conflict = $this->entityManager->wrapInTransaction(function () use ($mission, $accepted): ?string {
            $this->entityManager->refresh($mission, LockMode::PESSIMISTIC_WRITE);

            $conflict = $mission->getToolDecisionConflict();
            if (null === $conflict) {
                $accepted ? $mission->beginApprovedTool() : $mission->rejectToolApproval();
            }

            return $conflict;
        });

        if (null !== $conflict) {
            throw new MissionDecisionConflictException($conflict);
        }

        if (!$accepted) {
            return $mission;
        }

        try {
            $messages = $this->serializer->denormalize($mission->getAgentContext(), MessageBag::class);

            // The provider needs an answer to every call of the conversation, but only the authorized one runs.
            foreach ($messages->latestAs(Role::Assistant)->getToolCalls() as $toolCall) {
                $answer = self::NOT_EXECUTED;

                if ($toolCall->getId() === $mission->getPendingToolCallId()) {
                    $result = $this->toolbox->execute($toolCall)->getResult();

                    if (ToolApprovalListener::AWAITING_APPROVAL === $result) {
                        throw new \LogicException('The authorized tool was intercepted instead of running.');
                    }

                    $answer = $this->serializer->serialize($result, 'json');
                    $mission->finishApprovedTool($result);
                }

                $messages = $messages->with(Message::ofToolCall($toolCall, $answer));
            }

            // Stored before calling the model again: if it fails, the result is not lost.
            $this->entityManager->flush();

            $execution = $this->run($messages, ['tools' => [PublishSkyReport::NAME], 'tool_choice' => 'required'], $mission);

            $publication = $execution->getPendingToolCalls()[0] ?? throw new \UnexpectedValueException('Sky Pulse finished without requesting a publication.');

            $mission->requestApproval(trim($publication->getArguments()['title']), trim($publication->getArguments()['message']));
        } catch (\Throwable $exception) {
            $this->fail($mission, $exception);
        }

        $this->entityManager->flush();

        return $mission;
    }

    /** @throws MissionDecisionConflictException when another human decided first */
    public function decide(AgentMission $mission, bool $accepted): AgentMission
    {
        $conflict = $this->entityManager->wrapInTransaction(function () use ($mission, $accepted): ?string {
            $this->entityManager->refresh($mission, LockMode::PESSIMISTIC_WRITE);

            $conflict = $mission->getDecisionConflict();
            if (null === $conflict) {
                $accepted
                    ? $mission->approve($this->paperPlaneManager->create($this->participantManager->getSkyPulse(), (string) $mission->getProposedMessage()))
                    : $mission->reject();
            }

            return $conflict;
        });

        if (null !== $conflict) {
            throw new MissionDecisionConflictException($conflict);
        }

        return $mission;
    }

    /**
     * Arguments that are not even JSON end the run before any tool is requested, so ToolApprovalListener cannot send
     * them back: the model's only correction is asked for here. Any other failure of the provider is never retried.
     *
     * @param array<string, mixed> $options
     */
    private function run(MessageBag $messages, array $options, AgentMission $mission): ExecutionInterface
    {
        try {
            $execution = $this->agent->call($messages, $options);
            $this->record($execution, $mission);
        } catch (MalformedToolCallException $exception) {
            if (!$this->toolApproval->spendCorrection()) {
                throw $exception;
            }

            $execution = $this->agent->call($messages->with(Message::ofUser(self::MALFORMED_ARGUMENTS)), $options);
            $this->record($execution, $mission);
        }

        return $execution;
    }

    /** Runs the agent to its end, recording the tools it requested and the tokens it spent. */
    private function record(ExecutionInterface $execution, AgentMission $mission): void
    {
        foreach ($execution as $update) {
            if ($update instanceof Progress && $update->getPayload() instanceof ToolCall && Progress::STAGE_TOOL_CALL === $update->getStage()) {
                $mission->addStep(AgentMissionStepType::ToolCalled, $update->getPayload()->getName());
            }
        }

        $usage = $execution->getMetadata()->get('token_usage');

        if ($usage instanceof TokenUsageInterface) {
            $mission->addTokenUsage($usage->getPromptTokens() ?? 0, $usage->getCompletionTokens() ?? 0);
        }
    }

    private function fail(AgentMission $mission, \Throwable $exception): void
    {
        $this->logger->error('Sky Pulse mission failed.', ['mission' => $mission->getUuid()->toRfc4122(), 'exception' => $exception]);

        $mission->fail();
    }
}
