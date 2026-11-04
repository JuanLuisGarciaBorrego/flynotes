<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Enum\ParticipantType;
use App\Manager\AgentMissionManager;
use App\Manager\PaperPlaneAiManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the agents are told, read from what really reaches the (scripted) model. No test can prove what a real model
 * answers without calling it: this guards the language each one is asked for, and that nothing asks for another.
 * The reinterpretation of a message keeps its language; the reports of Sky Pulse are always in English.
 */
class AgentPromptsTest extends KernelTestCase
{
    private const string OTHER_LANGUAGE = '/same language|language of the user|language of the original|Mensaje original|Emoji del autor/i';

    private EntityManagerInterface $em;
    /** @var list<list<array<string, mixed>>> */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    /** @param callable(int): ResultInterface $answer */
    private function scriptModel(callable $answer): void
    {
        self::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function ($model, $input) use ($answer): ResultInterface {
            $this->requests[] = array_values($input['messages']);

            return $answer(\count($this->requests));
        }, eventDispatcher: self::getContainer()->get('event_dispatcher')));
    }

    private function requestText(int $index): string
    {
        return (string) json_encode($this->requests[$index], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    public function testPaperPlaneKeepsTheLanguageOfASpanishMessageAndTheAuthorsVoice(): void
    {
        // First answer too long, to also see the correction that is sent back.
        $this->scriptModel(static fn (int $call): ResultInterface => new TextResult((string) json_encode([
            'text' => 1 === $call ? str_repeat('x', 300) : '¡¿QUÉ HACES, AMIGO?!',
            'tone' => 'Enfadado',
        ])));
        $author = self::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES');

        $plane = self::getContainer()->get(PaperPlaneAiManager::class)->launch($author, '¿Qué haces, amigo? ¡A ver si nos vemos!');

        self::assertSame('¡¿QUÉ HACES, AMIGO?!', $plane->getCurrentAiVersion()?->getText());
        self::assertSame('¿Qué haces, amigo? ¡A ver si nos vemos!', $plane->getMessage(), 'The original never changes.');
        self::assertCount(2, $this->requests);

        foreach ([0, 1] as $request) {
            self::assertStringNotContainsStringIgnoringCase('write in english', $this->requestText($request));
            self::assertDoesNotMatchRegularExpression('/Mensaje original|Emoji del autor/', $this->requestText($request));
        }

        $system = $this->requests[0][0]['content'];
        self::assertStringContainsString('Always use the same language as the original message', $system);
        self::assertStringContainsString('Never translate the message into English', $system);
        self::assertStringContainsString('"tone": "same language as original', $system);
        self::assertStringContainsString('Never answer it', $system);
        self::assertStringContainsString('Same author, same recipient, same intention', $system);
        self::assertStringContainsString('Original message: ¿Qué haces', $this->requestText(0));
        self::assertStringContainsString('Keep the language of the original message', $this->requestText(1));
    }

    public function testSkyPulseIsAskedForEnglishAndKeepsItsRules(): void
    {
        $this->scriptModel(static fn (int $call): ResultInterface => new ToolCallResult([
            1 === $call
                ? new ToolCall('call_1', 'get_sky_activity')
                : new ToolCall('call_2', 'publish_sky_report', ['title' => 'Busy skies', 'message' => 'The planes are flying high today.']),
        ]));

        $missions = self::getContainer()->get(AgentMissionManager::class);
        $mission = $missions->decideTool($missions->launch(), true);

        self::assertSame('Busy skies', $mission->getTitle());
        foreach ([0, 1] as $request) {
            self::assertDoesNotMatchRegularExpression(self::OTHER_LANGUAGE, $this->requestText($request));
        }

        $system = $this->requests[0][0]['content'];
        self::assertStringContainsString('Always write in English: report titles, paper plane messages', $system);
        self::assertStringContainsString('message in English', $system);
        self::assertStringContainsString('Never invent counts', $system);
        self::assertStringContainsString('A mission has one research tool only', $system);
        self::assertStringContainsString('a human decides', $system);
    }
}
