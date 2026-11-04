<?php

declare(strict_types=1);

namespace App\Manager;

use App\Ai\Dto\PaperPlaneAiResponse;
use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Exception\AiMessageNotAllowedException;
use App\Exception\AiMessageStateException;
use App\Exception\AiUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * AI reinterpretation of a plane's message, shared by HTTP and MCP: launch(), generateNewPropose() and decide().
 *
 * Human in the loop: the AI only proposes. Every generation is kept as an AiMessageVersion (up to
 * PaperPlane::AI_MAX_ATTEMPTS) and only the author's final decision publishes one; the original message never changes.
 */
readonly class PaperPlaneAiManager
{
    public function __construct(
        private PaperPlaneManager $paperPlaneManager,
        #[Autowire(service: 'ai.agent.paper_plane')]
        private AgentInterface $agent,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private LockFactory $lockFactory,
        #[Autowire(env: 'bool:AI_ENABLED')]
        private bool $aiEnabled,
    ) {
    }

    /** What is locked while the AI writes a new version of a plane. Public for the tests that hold it. */
    public static function lockName(PaperPlane $plane): string
    {
        return 'paper-plane-ai-'.$plane->getUuid()->toRfc4122();
    }

    /**
     * Writing a version takes long, and meanwhile the visible one is about to be replaced: nothing can be decided or
     * generated on that plane. The caller releases what this returns.
     *
     * @throws AiMessageStateException when a generation is in progress
     */
    private function lockPlane(PaperPlane $plane): LockInterface
    {
        $lock = $this->lockFactory->createLock(self::lockName($plane));
        if (!$lock->acquire()) {
            throw new AiMessageStateException('The AI is still writing a version of this plane.');
        }

        return $lock;
    }

    public function launch(Participant $author, string $message): PaperPlane
    {
        $plane = $this->paperPlaneManager->create($author, $message);

        // Humans and agents keep flying without AI: the plane is launched without a proposal, as when the model fails.
        if (!$this->aiEnabled) {
            return $plane;
        }

        try {
            $propose = $this->generateReinterpretation($plane);
            $plane->proposeAiMessage($propose->text, $propose->tone);

            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to generate AI reinterpretation for paper plane.', [
                'plane' => $plane->getUuid()->toRfc4122(),
                'exception' => $exception,
            ]);
        }

        return $plane;
    }

    /** @throws AiUnavailableException when AI is disabled or the model fails: the plane does not change */
    public function generateNewPropose(PaperPlane $plane, Participant $participant): PaperPlane
    {
        if (null === $participant->getId() || $plane->getCreatedBy()?->getId() !== $participant->getId()) {
            throw new AiMessageNotAllowedException('Only the author can manage the AI message of this plane.');
        }

        $plane->assertCanProposeAiMessage();

        if (!$this->aiEnabled) {
            throw new AiUnavailableException();
        }

        $lock = $this->lockPlane($plane);
        try {
            try {
                $propose = $this->generateReinterpretation($plane);
            } catch (\Throwable $exception) {
                $this->logger->error('Failed to generate AI reinterpretation for paper plane.', [
                    'plane' => $plane->getUuid()->toRfc4122(),
                    'exception' => $exception,
                ]);

                throw new AiUnavailableException();
            }

            $plane->proposeAiMessage($propose->text, $propose->tone);

            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $plane;
    }

    public function decide(PaperPlane $plane, Participant $participant, bool $accepted, ?Uuid $versionId = null): PaperPlane
    {
        if (null === $participant->getId() || $plane->getCreatedBy()?->getId() !== $participant->getId()) {
            throw new AiMessageNotAllowedException('Only the author can manage the AI message of this plane.');
        }

        $lock = $this->lockPlane($plane);
        try {
            if ($accepted) {
                if (null === $versionId) {
                    throw new AiMessageStateException('The AI version to approve is required.');
                }

                $version = $plane->findAiVersion($versionId)
                    ?? throw new AiMessageStateException('This AI version does not belong to the plane.');

                $plane->acceptAiVersion($version);
            } else {
                $plane->rejectAiMessage();
            }

            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $plane;
    }

    private function generateReinterpretation(PaperPlane $plane): PaperPlaneAiResponse
    {
        $message = sprintf(
            "Original message: %s\nAuthor's emoji: %s",
            $plane->getMessage(),
            $plane->getCreatedBy()?->getEmoji() ?? '',
        );

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $response = $this->agent->call($message, ['response_format' => ['type' => 'json_object']])->getContent();
            } catch (PlatformRuntimeException $exception) {
                // An answer that is not JSON is invalid content, to correct below. Anything else is a provider failure: never retried.
                if (!$exception->getPrevious() instanceof \JsonException) {
                    throw $exception;
                }
                $response = null;
            }

            if (!is_array($response) || !is_string($response['text'] ?? null) || (null !== ($response['tone'] ?? null) && !is_string($response['tone']))) {
                $error = 'Invalid AI response format.';
            } else {
                $result = new PaperPlaneAiResponse(
                    text: $response['text'],
                    tone: $response['tone'] ?? null,
                );

                $violations = $this->validator->validate($result);

                if (0 === count($violations)) {
                    return $result;
                }

                $error = (string) $violations;
            }

            if (1 === $attempt) {
                throw new \UnexpectedValueException($error);
            }

            $message .= sprintf(
                "\n\nThe previous response does not meet the requirements: %s\n".
                'Generate a new version correcting these errors. '.
                'Return only valid JSON, without Markdown formatting. '.
                'The text field must not exceed 250 characters. '.
                'Keep the language of the original message, its meaning and the tone inspired by the author\'s emoji.',
                $error,
            );
        }

        throw new \LogicException('AI generation finished without a valid response.');
    }
}
