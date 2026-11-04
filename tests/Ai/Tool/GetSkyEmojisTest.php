<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use App\Ai\Tool\GetSkyEmojis;
use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use App\Repository\ParticipantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** The rule on its own, and then the real tool in the whole mission over HTTP, with only the model's answers scripted. */
class GetSkyEmojisTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<mixed> */
    private array $modelInputs = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function ($model, $input): ResultInterface {
            $this->modelInputs[] = $input;

            return new ToolCallResult(1 === \count($this->modelInputs)
                ? [new ToolCall('call_research', 'get_sky_emojis')]
                : [new ToolCall('call_publish', 'publish_sky_report', ['title' => 'Un cielo de símbolos', 'message' => 'Muchos símbolos comparten el cielo.'])]);
        }));
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    /** @return iterable<string, array{array<string, int>, array<string, int>}> real counts, result */
    public static function emojis(): iterable
    {
        yield 'nothing to count' => [[], []];
        yield 'one participant is listed' => [['🦁' => 1], ['🦁' => 1]];
        yield 'small and large groups are all listed' => [
            ['🦊' => 12, '🐼' => 5, '🐸' => 2, '🦁' => 1],
            ['🦊' => 12, '🐼' => 5, '🐸' => 2, '🦁' => 1],
        ];
    }

    /**
     * @param array<string, int> $real
     * @param array<string, int> $expected
     */
    #[DataProvider('emojis')]
    public function testEveryEmojiIsListedAndTheFormatIsKept(array $real, array $expected): void
    {
        $repository = $this->createStub(ParticipantRepository::class);
        $repository->method('countByEmoji')->willReturn(self::rows($real));

        self::assertSame(self::rows($expected), new GetSkyEmojis($repository)());
    }

    public function testTheModelTheTimelineAndThePublicGetReceiveTheSameData(): void
    {
        // The human who decides is the fourth fox.
        $this->participants(['🦊' => 3, '🐼' => 3, '🐸' => 2, '🦁' => 1]);
        $uuid = $this->runMissionUpToTheProposal();

        $expected = [['emoji' => '🦊', 'total' => 4], ['emoji' => '🐼', 'total' => 3], ['emoji' => '🐸', 'total' => 2], ['emoji' => '🦁', 'total' => 1]];

        $messages = array_values($this->modelInputs[1]['messages']);
        self::assertSame('tool', $messages[3]['role']);
        self::assertSame($expected, json_decode($messages[3]['content'], true), 'The model.');

        self::assertSame($expected, $this->storedResult(), 'The timeline.');

        self::assertSame($expected, $this->publicResult($uuid), 'The public GET.');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /** @param array<string, int> $totals */
    private function participants(array $totals): void
    {
        foreach ($totals as $emoji => $total) {
            for ($i = 0; $i < $total; ++$i) {
                $this->participant($emoji);
            }
        }
    }

    private function participant(string $emoji): Participant
    {
        $participant = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Humana', 'ES')->setEmoji($emoji);
        $this->em->flush();

        return $participant;
    }

    private function runMissionUpToTheProposal(): string
    {
        $this->client->loginUser($this->participant('🦊'), 'main');
        $uuid = $this->post('/api/agent_missions', '{}')['uuid'];

        $decided = $this->post(sprintf('/api/agent_missions/%s/tool-decision', $uuid), '{"accepted": true}');
        self::assertSame('waiting_approval', $decided['status']);

        return $uuid;
    }

    /** @return array<string, mixed> */
    private function post(string $uri, string $body): array
    {
        $this->client->request('POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ], content: $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return list<array{emoji: string, total: int}> */
    private function storedResult(): array
    {
        $payload = $this->em->getConnection()->fetchOne("SELECT payload FROM agent_mission_step WHERE type = 'tool_succeeded'");

        return json_decode((string) $payload, true)['result'];
    }

    /** @return list<array{emoji: string, total: int}> */
    private function publicResult(string $uuid): array
    {
        $this->client->getCookieJar()->clear();
        $this->em->clear();
        $this->client->request('GET', '/api/agent_missions/'.$uuid, server: ['HTTP_ACCEPT' => 'application/json']);
        $steps = json_decode((string) $this->client->getResponse()->getContent(), true)['steps'];

        return array_values(array_filter($steps, static fn (array $step): bool => \array_key_exists('result', $step)))[0]['result'];
    }

    /**
     * @param array<string, int> $totals
     *
     * @return list<array{emoji: string, total: int}>
     */
    private static function rows(array $totals): array
    {
        return array_map(
            static fn (string $emoji, int $total): array => ['emoji' => $emoji, 'total' => $total],
            array_keys($totals),
            array_values($totals),
        );
    }
}
