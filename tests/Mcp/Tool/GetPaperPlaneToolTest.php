<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Manager\PaperPlaneAiManager;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Manager\PlaneVisitManager;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_paper_plane` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28.
 */
class GetPaperPlaneToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';
    private const string MESSAGE = 'Hola desde un avión de papel';
    private const string UNKNOWN_UUID = '0198f1a2-7b3c-7d4e-8f50-123456789abc';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $author;
    private Participant $stamper;
    private PaperPlane $plane;
    private PlaneVisit $stamp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->author = $this->participant(ParticipantType::Human, 'ES');
        $this->stamper = $this->participant(ParticipantType::Human, 'FR');
        $this->plane = static::getContainer()->get(PaperPlaneManager::class)->create($this->author, self::MESSAGE);
        $this->stamp = static::getContainer()->get(PlaneVisitManager::class)->registerParticipation($this->plane, $this->stamper);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testToolIsDiscoverable(): void
    {
        $data = $this->mcp('tools/list', []);

        self::assertResponseIsSuccessful();
        $tools = array_column($data['result']['tools'], null, 'name');
        self::assertArrayHasKey('get_paper_plane', $tools);
        // The other domain and participant tools share the server.
        foreach (['launch_paper_plane', 'list_paper_planes', 'register_agent', 'get_profile'] as $name) {
            self::assertArrayHasKey($name, $tools);
        }

        $tool = $tools['get_paper_plane'];
        self::assertSame(['uuid'], array_keys($tool['inputSchema']['properties']));
        self::assertSame('uuid', $tool['inputSchema']['properties']['uuid']['format']);
        self::assertSame(['uuid'], $tool['inputSchema']['required']);
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        // A read records a visit, so the tool is not read-only.
        self::assertFalse($tool['annotations']['readOnlyHint']);
    }

    public function testAnonymousAgentReadsThePublicDetail(): void
    {
        $data = $this->read(null);

        self::assertResponseIsSuccessful();
        $result = $data['result'];
        self::assertFalse($result['isError'] ?? false);

        $detail = $result['structuredContent'];
        self::assertSame([
            'uuid' => $this->plane->getUuid()->toRfc4122(),
            'message' => self::MESSAGE,
            'authorName' => $this->author->getName(),
            'authorEmoji' => $this->author->getEmoji(),
            'authorCountryCode' => 'ES',
            'createdAt' => $this->plane->getCreatedAt()->format(\DATE_ATOM),
            'viewCount' => 1,
            'stamps' => [[
                'uuid' => $this->stamp->getUuid()->toRfc4122(),
                'countryCode' => 'FR',
                'emoji' => $this->stamper->getEmoji(),
                'createdAt' => $this->stamp->getCreatedAt()->format(\DATE_ATOM),
            ]],
            'isAuthor' => false,
            'hasStamped' => false,
            'canStamp' => false,
        ], $detail);
        self::assertSame($detail, json_decode($result['content'][0]['text'], true));
    }

    /** The SDK validates structuredContent against the declared outputSchema: a mismatch would be an isError result. */
    public function testResponseMatchesTheOutputSchema(): void
    {
        $tools = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name');
        $schema = $tools['get_paper_plane']['outputSchema'];

        $detail = $this->read(null)['result'];

        self::assertFalse($detail['isError'] ?? false);
        self::assertSame($schema['required'], array_keys($detail['structuredContent']));
        self::assertSame(array_keys($schema['properties']), array_keys($detail['structuredContent']));
        self::assertSame($schema['properties']['stamps']['items']['required'], array_keys($detail['structuredContent']['stamps'][0]));
    }

    public function testReadsAreRecordedAsVisits(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'PT');

        self::assertSame(1, $this->read('Bearer '.$bot->getCode())['result']['structuredContent']['viewCount']);
        self::assertSame(2, $this->read('Bearer '.$bot->getCode())['result']['structuredContent']['viewCount'], 'Repeated reads are all counted.');
        self::assertSame(3, $this->read(null)['result']['structuredContent']['viewCount'], 'Anonymous reads count too.');

        $views = $this->views();
        self::assertCount(3, $views);
        self::assertSame($bot->getId(), $views[0]->getParticipant()?->getId());
        self::assertSame($bot->getId(), $views[1]->getParticipant()?->getId());
        self::assertNull($views[2]->getParticipant());
    }

    public function testAuthorReadIsNotAVisit(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'PT');
        $own = static::getContainer()->get(PaperPlaneManager::class)->create($bot, 'Mi propio avión de papel');

        $detail = $this->read('Bearer '.$bot->getCode(), $own->getUuid()->toRfc4122())['result']['structuredContent'];

        self::assertSame(0, $detail['viewCount']);
        self::assertTrue($detail['isAuthor']);
        self::assertFalse($detail['canStamp']);
        self::assertCount(0, $this->views($own));
    }

    public function testBotViewerFlags(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'PT');

        $detail = $this->read('Bearer '.$bot->getCode())['result']['structuredContent'];

        self::assertFalse($detail['isAuthor']);
        self::assertFalse($detail['hasStamped']);
        // Bots with a country can stamp planes by others.
        self::assertTrue($detail['canStamp']);
    }

    public function testBotThatAlreadyStampedCannotStampAgain(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'PT');
        static::getContainer()->get(PlaneVisitManager::class)->registerParticipation($this->plane, $bot);

        $detail = $this->read('Bearer '.$bot->getCode())['result']['structuredContent'];

        self::assertTrue($detail['hasStamped']);
        self::assertFalse($detail['canStamp']);
        self::assertSame(['FR', 'PT'], array_column($detail['stamps'], 'countryCode'));
    }

    public function testBotWithoutCountryCannotStamp(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        self::assertFalse($this->read('Bearer '.$bot->getCode())['result']['structuredContent']['canStamp']);
    }

    public function testUnknownPlaneIsAToolErrorAndRecordsNothing(): void
    {
        $before = $this->countVisits();

        $data = $this->read(null, self::UNKNOWN_UUID);

        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertSame('Paper plane not found.', $data['result']['content'][0]['text']);
        self::assertSame($before, $this->countVisits());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedArguments(): iterable
    {
        yield 'missing uuid' => [[]];
        yield 'empty uuid' => [['uuid' => '']];
        yield 'not a uuid' => [['uuid' => 'not-a-uuid']];
        yield 'uuid without dashes' => [['uuid' => '0198f1a27b3c7d4e8f50123456789abc']];
        yield 'non-string uuid' => [['uuid' => 42]];
        yield 'chosen viewer' => [['uuid' => self::UNKNOWN_UUID, 'participant' => self::UNKNOWN_UUID]];
        yield 'skip the visit' => [['uuid' => self::UNKNOWN_UUID, 'recordView' => false]];
    }

    /** @param array<string, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedArguments')]
    public function testArgumentsOutsideTheSchemaAreRejected(array $arguments): void
    {
        $before = $this->countVisits();

        $data = $this->mcp('tools/call', ['name' => 'get_paper_plane', 'arguments' => (object) $arguments], null, ['HTTP_MCP_NAME' => 'get_paper_plane']);

        self::assertSame(-32602, $data['error']['code']);
        self::assertSame($before, $this->countVisits());
    }

    public function testNoCredentialsOrParticipantIdentifiersAreExposed(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'PT');

        $this->read('Bearer '.$bot->getCode());

        $content = (string) $this->client->getResponse()->getContent();
        foreach ([$bot, $this->author, $this->stamper] as $participant) {
            self::assertStringNotContainsString($participant->getCode(), $content);
            self::assertStringNotContainsString($participant->getUuid()->toRfc4122(), $content);
        }
        self::assertStringNotContainsString('"code"', $content);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    /** @return list<PlaneVisit> */
    private function views(?PaperPlane $plane = null): array
    {
        return static::getContainer()->get(PlaneVisitRepository::class)->findBy(
            ['plane' => $plane ?? $this->plane, 'type' => PlaneVisitType::View],
            ['id' => 'ASC'],
        );
    }

    private function countVisits(): int
    {
        return static::getContainer()->get(PlaneVisitRepository::class)->count([]);
    }

    /** get_paper_plane only opens the original message: the AI proposals belong to the author's own tools. */
    public function testNoAiMessageIsExposed(): void
    {
        $proposal = 'Una propuesta escrita por la IA.';
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(
            static fn (): string => json_encode(['text' => $proposal, 'tone' => 'Tierno'], \JSON_THROW_ON_ERROR),
            eventDispatcher: static::getContainer()->get('event_dispatcher'),
        ));
        $ai = static::getContainer()->get(PaperPlaneAiManager::class);
        $pending = $ai->launch($this->author, 'Un avión con propuesta pendiente');
        $accepted = $ai->launch($this->author, 'Un avión con propuesta aceptada');
        $ai->decide($accepted, $this->author, true, $accepted->getCurrentAiVersion()?->getUuid());
        $bot = $this->participant(ParticipantType::Bot, 'PT');

        foreach ([$pending, $accepted] as $plane) {
            $result = $this->read('Bearer '.$bot->getCode(), $plane->getUuid()->toRfc4122())['result'];
            self::assertFalse($result['isError'] ?? false);
            self::assertArrayNotHasKey('aiMessage', $result['structuredContent']);
            self::assertStringNotContainsString($proposal, (string) json_encode($result, \JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array<string, mixed> */
    private function read(?string $authorization, ?string $uuid = null): array
    {
        return $this->mcp(
            'tools/call',
            ['name' => 'get_paper_plane', 'arguments' => ['uuid' => $uuid ?? $this->plane->getUuid()->toRfc4122()]],
            $authorization,
            ['HTTP_MCP_NAME' => 'get_paper_plane'],
        );
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $extraServer
     *
     * @return array<string, mixed>
     */
    private function mcp(string $method, array $params, ?string $authorization = null, array $extraServer = []): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION,
            'HTTP_MCP_METHOD' => $method,
            ...$extraServer,
        ];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];

        $this->client->request('POST', '/mcp', server: $server, content: json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            \JSON_THROW_ON_ERROR,
        ));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function participant(ParticipantType $type, ?string $countryCode = null): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, 'Test '.$type->value, $countryCode);
    }
}
