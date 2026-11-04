<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Ai\Tool\PublishSkyReport;
use App\Entity\AgentMission;
use App\Entity\PaperPlane;
use App\Repository\AgentMissionRepository;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Human-in-the-loop approval of Symfony AI tools.
 *
 * Symfony AI uses the Toolbox to execute tools requested by the model.
 * During execution, the Toolbox dispatches events that allow us to
 * inspect, validate, intercept and monitor tool calls.
 *
 * Symfony AI Toolbox events (execution lifecycle):
 *
 * 1. ToolCallRequested [USED]
 *    Dispatched before executing a tool requested by the model.
 *
 *    Provides access to the ToolCall, including its ID, name and arguments.
 *    A listener can:
 *    - Let the tool execute normally.
 *    - Deny the call using deny(), returning the reason to the model.
 *    - Replace its execution using setResult() with a custom ToolResult.
 *
 *    In FlyNotes:
 *    - Research tools are intercepted until a human authorizes their call.
 *    - An authorized research tool is allowed to execute normally.
 *    - publish_sky_report is validated and always intercepted. The model gets one chance
 *      to correct an invalid request: another one fails the mission without calling it again.
 *
 * 2. ToolCallArgumentsResolved [NOT USED]
 *    Dispatched when the Toolbox resolves the arguments required to
 *    invoke the PHP tool.
 *
 *    Useful for inspecting the resolved arguments before execution.
 *    It is skipped when ToolCallRequested already intercepts the call
 *    and supplies a result without invoking the tool.
 *
 * 3. ToolCallSucceeded [NOT USED]
 *    Dispatched after a tool has executed successfully.
 *
 *    Provides access to the successful execution result.
 *    Useful for logging, metrics, auditing or recording tool results.
 *
 *    Intercepted tools do not trigger this event because their
 *    PHP methods are never executed.
 *
 * 4. ToolCallFailed [NOT USED]
 *    Dispatched when a tool fails during execution.
 *
 *    Useful for logging errors, monitoring failures or auditing
 *    unsuccessful tool executions.
 *
 *    It does not indicate that a human rejected an approval request.
 *    A rejected or intercepted call is not necessarily an execution failure.
 *
 * 5. ToolCallsExecuted [USED]
 *    Dispatched by the agent execution loop after processing a round
 *    of tool calls.
 *
 *    Provides the ToolResults produced during that round.
 *    In FlyNotes, we identify calls intercepted with AWAITING_APPROVAL
 *    and use setResult() to preserve their original ToolCalls as pending.
 *
 *    The agent stops instead of continuing with another model round.
 *    AgentMissionManager retrieves the unanswered calls through
 *    ExecutionInterface::getPendingToolCalls().
 *
 * Execution flow for a normal tool:
 *
 * AgentInterface::call()
 *   -> Model requests a tool
 *   -> ToolCallRequested
 *   -> ToolCallArgumentsResolved
 *   -> PHP tool execution
 *   -> ToolCallSucceeded / ToolCallFailed
 *   -> ToolCallsExecuted
 *   -> Model continues with the tool result
 *
 * Execution flow for an intercepted tool:
 *
 * AgentInterface::call()
 *   -> Model requests a tool
 *   -> ToolCallRequested
 *   -> setResult(AWAITING_APPROVAL)
 *   -> PHP tool is NOT executed
 *   -> ToolCallsExecuted
 *   -> Pending ToolCall is preserved
 *   -> AgentMission waits for human approval
 *
 * Human approval happens in a separate HTTP request.
 *
 * Research tools:
 *   AgentMissionManager::decideTool() executes an authorized call through
 *   ToolboxInterface::execute(). This dispatches the individual tool
 *   execution events, but not ToolCallsExecuted, which belongs to the
 *   agent's execution loop.
 *
 * Publication:
 *   publish_sky_report is never executed directly.
 *   AgentMissionManager::decide() publishes the paper plane only after
 *   a human approves the proposed report.
 *
 * The authorization is stored in PostgreSQL and linked to the original
 * ToolCall ID. This listener does not retain authorization state.
 *
 * Note: not every event is dispatched on every execution path.
 * Intercepted tools skip the normal argument resolution and execution
 * events.
 *
 * @see https://symfony.com/doc/current/ai/components/agent.html
 */
final class ToolApprovalListener implements ResetInterface
{
    public const array RESEARCH_TOOLS = ['get_sky_emojis', 'get_sky_countries', 'get_sky_activity'];
    public const string AWAITING_APPROVAL = 'Awaiting human approval.';

    /** Per request: a request continues one mission at most, and workers reuse this service between requests. */
    private bool $correctionRequested = false;

    public function __construct(
        private readonly AgentMissionRepository $missions,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function reset(): void
    {
        $this->correctionRequested = false;
    }

    /** Whether the model can still be asked to correct itself: it only can once. */
    public function spendCorrection(): bool
    {
        $available = !$this->correctionRequested;
        $this->correctionRequested = true;

        return $available;
    }

    #[AsEventListener]
    public function onToolCallRequested(ToolCallRequested $event): void
    {
        $toolCall = $event->getToolCall();

        if (PublishSkyReport::NAME === $toolCall->getName()) {
            $violations = $this->validator->validate($toolCall->getArguments(), new Assert\Collection(fields: [
                'title' => new Assert\Sequentially([
                    new Assert\Type('string'),
                    new Assert\NotBlank(normalizer: 'trim'),
                    new Assert\Length(max: AgentMission::TITLE_MAX_LENGTH, normalizer: 'trim'),
                ]),
                'message' => new Assert\Sequentially([
                    new Assert\Type('string'),
                    new Assert\NotBlank(normalizer: 'trim'),
                    new Assert\Length(min: PaperPlane::MESSAGE_MIN_LENGTH, max: PaperPlane::MESSAGE_MAX_LENGTH, normalizer: 'trim'),
                ]),
            ]));

            if (\count($violations) > 0) {
                // Thrown here, because a denial makes the agent call the model again, and that call is paid.
                if (!$this->spendCorrection()) {
                    throw new \UnexpectedValueException('Sky Pulse did not correct its publication request: '.$violations);
                }

                // The reason goes back to the model, to correct it.
                $event->deny('Invalid publication request: '.$violations);

                return;
            }
        } elseif (!\in_array($toolCall->getName(), self::RESEARCH_TOOLS, true) || $this->missions->isToolCallApproved($toolCall->getId(), $toolCall->getName())) {
            return;
        }

        $event->setResult(new ToolResult($toolCall, self::AWAITING_APPROVAL));
    }

    #[AsEventListener]
    public function onToolCallsExecuted(ToolCallsExecuted $event): void
    {
        $pending = [];
        foreach ($event->getToolResults() as $toolResult) {
            if (self::AWAITING_APPROVAL === $toolResult->getResult()) {
                $pending[] = $toolResult->getToolCall();
            }
        }

        if ([] !== $pending) {
            $event->setResult(new ToolCallResult($pending));
        }
    }
}
