<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Ai\Tool\PublishSkyReport;
use App\Entity\AgentMission;
use App\Entity\PaperPlane;
use App\EventListener\ToolApprovalListener;
use App\Repository\AgentMissionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** The two events on their own. The whole cycle with the real agent is in AgentMissionManagerTest and the Api tests. */
class ToolApprovalListenerTest extends KernelTestCase
{
    private const string MESSAGE = 'Siete aviones vuelan por el cielo.';

    private function listener(?AgentMissionRepository $missions = null, ?ValidatorInterface $validator = null): ToolApprovalListener
    {
        return new ToolApprovalListener(
            $missions ?? $this->createStub(AgentMissionRepository::class),
            $validator ?? Validation::createValidator(),
        );
    }

    private function requested(string $name, array $arguments = [], string $id = 'call_1'): ToolCallRequested
    {
        return new ToolCallRequested(new ToolCall($id, $name, $arguments), new Tool(new ExecutionReference(self::class), $name, 'A tool.'));
    }

    private function publication(array $arguments = ['title' => 'El misterio', 'message' => self::MESSAGE], string $id = 'call_publish'): ToolCallRequested
    {
        return $this->requested(PublishSkyReport::NAME, $arguments, $id);
    }

    /** The result the listener gives to an intercepted call, as the agent loop would collect it. */
    private function intercepted(ToolCallRequested $event): ToolResult
    {
        $this->listener()->onToolCallRequested($event);

        return $event->getResult() ?? self::fail('The call was not intercepted.');
    }

    /** @return iterable<string, array{string}> */
    public static function researchTools(): iterable
    {
        foreach (ToolApprovalListener::RESEARCH_TOOLS as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('researchTools')]
    public function testAnUnauthorizedResearchToolIsIntercepted(string $name): void
    {
        $missions = $this->createMock(AgentMissionRepository::class);
        $missions->expects(self::once())->method('isToolCallApproved')->with('call_1', $name)->willReturn(false);
        $event = $this->requested($name);

        $this->listener($missions)->onToolCallRequested($event);

        self::assertTrue($event->hasResult());
        self::assertSame($event->getToolCall(), $event->getResult()->getToolCall());
        self::assertFalse($event->isDenied());
    }

    #[DataProvider('researchTools')]
    public function testAnAuthorizedResearchToolIsLeftToRun(string $name): void
    {
        $missions = $this->createStub(AgentMissionRepository::class);
        $missions->method('isToolCallApproved')->willReturn(true);
        $event = $this->requested($name);

        $this->listener($missions)->onToolCallRequested($event);

        self::assertFalse($event->hasResult());
        self::assertFalse($event->isDenied());
    }

    public function testValidArgumentsLeaveThePublicationPendingWithoutLookingAtAuthorizations(): void
    {
        $missions = $this->createMock(AgentMissionRepository::class);
        $missions->expects(self::never())->method('isToolCallApproved');
        $event = $this->publication();

        $this->listener($missions)->onToolCallRequested($event);

        self::assertTrue($event->hasResult());
        self::assertFalse($event->isDenied());
    }

    /** There is no such thing as an approved publication here: it stays intercepted even if the row says otherwise. */
    public function testThePublicationIsInterceptedWhateverTheAuthorizations(): void
    {
        $missions = $this->createStub(AgentMissionRepository::class);
        $missions->method('isToolCallApproved')->willReturn(true);
        $event = $this->publication();

        $this->listener($missions)->onToolCallRequested($event);

        self::assertTrue($event->hasResult());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPublications(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'no message' => [['title' => 'El misterio']];
        yield 'no title' => [['message' => self::MESSAGE]];
        yield 'an unknown argument' => [['title' => 'El misterio', 'message' => self::MESSAGE, 'author' => 'Otra']];
        yield 'blank title' => [['title' => '   ', 'message' => self::MESSAGE]];
        yield 'title not a string' => [['title' => 7, 'message' => self::MESSAGE]];
        yield 'title too long' => [['title' => str_repeat('x', AgentMission::TITLE_MAX_LENGTH + 1), 'message' => self::MESSAGE]];
        yield 'message not a string' => [['title' => 'El misterio', 'message' => ['x']]];
        yield 'message too short' => [['title' => 'El misterio', 'message' => str_repeat('x', PaperPlane::MESSAGE_MIN_LENGTH - 1)]];
        yield 'message too short once trimmed' => [['title' => 'El misterio', 'message' => '  '.str_repeat('x', PaperPlane::MESSAGE_MIN_LENGTH - 1).'  ']];
        yield 'message too long' => [['title' => 'El misterio', 'message' => str_repeat('x', PaperPlane::MESSAGE_MAX_LENGTH + 1)]];
    }

    #[DataProvider('invalidPublications')]
    public function testInvalidArgumentsAreDeniedForTheModelToCorrect(array $arguments): void
    {
        $event = $this->publication($arguments);

        $this->listener()->onToolCallRequested($event);

        self::assertTrue($event->isDenied());
        self::assertStringStartsWith('Invalid publication request: ', (string) $event->getDenialReason());
        self::assertFalse($event->hasResult());
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $event = $this->publication(['title' => str_repeat('x', AgentMission::TITLE_MAX_LENGTH), 'message' => str_repeat('x', PaperPlane::MESSAGE_MAX_LENGTH)]);

        $this->listener()->onToolCallRequested($event);

        self::assertTrue($event->hasResult());
    }

    public function testResearchCallsAreNotValidatedAsAPublication(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::never())->method('validate');

        $this->listener(validator: $validator)->onToolCallRequested($this->requested('get_sky_emojis', ['title' => 7]));
    }

    public function testAnyOtherToolIsNotItsBusiness(): void
    {
        $missions = $this->createMock(AgentMissionRepository::class);
        $missions->expects(self::never())->method('isToolCallApproved');
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::never())->method('validate');
        $event = $this->requested('drop_everything');

        $this->listener($missions, $validator)->onToolCallRequested($event);

        self::assertFalse($event->hasResult());
        self::assertFalse($event->isDenied());
    }

    /**
     * @param list<ToolResult> $results
     *
     * @return list<string>|null the ids left pending, or null when the agent goes on
     */
    private function pendingAfter(array $results): ?array
    {
        $event = new ToolCallsExecuted($results);

        $this->listener()->onToolCallsExecuted($event);

        if (!$event->hasResult()) {
            return null;
        }

        self::assertInstanceOf(ToolCallResult::class, $event->getResult());

        return array_map(static fn (ToolCall $call): string => $call->getId(), array_values($event->getResult()->getContent()));
    }

    public function testEveryInterceptedResearchCallStaysPendingInOrder(): void
    {
        self::assertSame(['call_a', 'call_b'], $this->pendingAfter([
            $this->intercepted($this->requested('get_sky_emojis', id: 'call_a')),
            $this->intercepted($this->requested('get_sky_activity', id: 'call_b')),
        ]));
    }

    public function testADeniedPublicationAloneLetsTheModelTryAgain(): void
    {
        $denied = $this->publication(['title' => '']);
        $this->listener()->onToolCallRequested($denied);

        self::assertNull($this->pendingAfter([new ToolResult($denied->getToolCall(), (string) $denied->getDenialReason())]));
    }

    public function testADeniedCallDoesNotHideAValidPublication(): void
    {
        self::assertSame(['call_valid'], $this->pendingAfter([
            new ToolResult(new ToolCall('call_denied', PublishSkyReport::NAME), 'Invalid publication request: x'),
            $this->intercepted($this->publication(id: 'call_valid')),
        ]));
    }

    public function testRealResultsLetTheAgentGoOn(): void
    {
        self::assertNull($this->pendingAfter([new ToolResult(new ToolCall('call_1', 'get_sky_activity'), ['planes' => 7])]));
        self::assertNull($this->pendingAfter([]));
    }

    /** @return iterable<string, array{class-string}> */
    public static function events(): iterable
    {
        yield 'ToolCallRequested' => [ToolCallRequested::class];
        yield 'ToolCallsExecuted' => [ToolCallsExecuted::class];
    }

    /** @param class-string $event */
    #[DataProvider('events')]
    public function testItIsTheOnlyListenerOfTheApplicationOnEachEvent(string $event): void
    {
        self::bootKernel();

        $own = [];
        foreach (self::getContainer()->get('event_dispatcher')->getListeners($event) as $listener) {
            if (\is_array($listener) && str_starts_with($listener[0]::class, 'App\\')) {
                $own[] = $listener[0]::class;
            }
        }

        self::assertSame([ToolApprovalListener::class], $own);
    }
}
