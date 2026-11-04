<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Helper\StampColorHelper;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `stamp_paper_plane` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28.
 */
class StampPaperPlaneToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';
    private const string UNKNOWN_UUID = '0198f1a2-7b3c-7d4e-8f50-123456789abc';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $author;
    private Participant $bot;
    private PaperPlane $plane;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // A distinct "origin" per test so the persisted rate limiter state never leaks between tests/runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->author = $this->participant(ParticipantType::Human, 'ES');
        $this->bot = $this->participant(ParticipantType::Bot, 'PT');
        $this->plane = static::getContainer()->get(PaperPlaneManager::class)->create($this->author, 'Hola desde un avión de papel');
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
        // Every tool shares the server.
        foreach (['register_agent', 'get_profile', 'launch_paper_plane', 'list_paper_planes', 'get_paper_plane', 'stamp_paper_plane'] as $name) {
            self::assertArrayHasKey($name, $tools);
        }

        $tool = $tools['stamp_paper_plane'];
        // Only the plane: participant, country and emoji are never client input.
        self::assertSame(['uuid'], array_keys($tool['inputSchema']['properties']));
        self::assertSame('uuid', $tool['inputSchema']['properties']['uuid']['format']);
        self::assertSame(['uuid'], $tool['inputSchema']['required']);
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        self::assertFalse($tool['annotations']['readOnlyHint']);
        self::assertSame(['uuid', 'stampUuid', 'color', 'countryCode', 'countryName', 'emoji', 'createdAt'], array_keys($tool['outputSchema']['properties']));
        self::assertFalse($tool['outputSchema']['additionalProperties']);
    }

    public function testBotStampsAPlaneByAnotherParticipantWithItsOwnCountryAndEmoji(): void
    {
        $data = $this->stamp('Bearer '.$this->bot->getCode());

        self::assertResponseIsSuccessful();
        $result = $data['result'];
        self::assertFalse($result['isError'] ?? false);

        $stamps = $this->participations();
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];
        self::assertSame($this->bot->getId(), $stamp->getParticipant()?->getId());
        self::assertSame('PT', $stamp->getCountryCode());
        self::assertSame($this->bot->getEmoji(), $stamp->getEmoji());

        self::assertSame([
            'uuid' => $this->plane->getUuid()->toRfc4122(),
            'stampUuid' => $stamp->getUuid()->toRfc4122(),
            'color' => static::getContainer()->get(StampColorHelper::class)->colorFor($stamp->getUuid()),
            'countryCode' => 'PT',
            'countryName' => 'Portugal',
            'emoji' => $this->bot->getEmoji(),
            'createdAt' => $stamp->getCreatedAt()?->format(\DATE_ATOM),
        ], $result['structuredContent']);
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));
    }

    /** The SDK validates structuredContent against the declared outputSchema: a mismatch would be an isError result. */
    public function testResponseMatchesTheOutputSchema(): void
    {
        $schema = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name')['stamp_paper_plane']['outputSchema'];

        $result = $this->stamp('Bearer '.$this->bot->getCode())['result'];

        self::assertFalse($result['isError'] ?? false);
        self::assertSame($schema['required'], array_keys($result['structuredContent']));
        self::assertSame(array_keys($schema['properties']), array_keys($result['structuredContent']));
        self::assertTrue(Uuid::isValid($result['structuredContent']['stampUuid']));
    }

    public function testStampShowsUpInGetPaperPlaneAndIsNotARead(): void
    {
        $stampUuid = $this->stamp('Bearer '.$this->bot->getCode())['result']['structuredContent']['stampUuid'];

        self::assertCount(0, $this->views(), 'Stamping records no visit.');

        $detail = $this->mcp('tools/call', ['name' => 'get_paper_plane', 'arguments' => ['uuid' => $this->plane->getUuid()->toRfc4122()]], 'Bearer '.$this->bot->getCode(), ['HTTP_MCP_NAME' => 'get_paper_plane'])['result']['structuredContent'];

        self::assertSame(1, $detail['viewCount'], 'Only the get_paper_plane read is counted.');
        self::assertSame([$stampUuid], array_column($detail['stamps'], 'uuid'));
        self::assertSame(['PT'], array_column($detail['stamps'], 'countryCode'));
        self::assertTrue($detail['hasStamped']);
        self::assertFalse($detail['canStamp']);
    }

    public function testAnonymousCallIsRejected(): void
    {
        $data = $this->stamp(null);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertStringContainsString('Authentication required', $data['result']['content'][0]['text']);
        self::assertCount(0, $this->participations());
    }

    public function testHumanCodeIsRejectedByTheFirewall(): void
    {
        $human = $this->participant(ParticipantType::Human, 'FR');

        $this->stamp('Bearer '.$human->getCode());

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString($human->getCode(), (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->participations());
    }

    public function testBotCannotStampItsOwnPlane(): void
    {
        $own = static::getContainer()->get(PaperPlaneManager::class)->create($this->bot, 'Mi propio avión de papel');

        $data = $this->stamp('Bearer '.$this->bot->getCode(), $own->getUuid()->toRfc4122());

        $this->assertToolError($data, 'You cannot stamp your own plane.');
        self::assertCount(0, $this->participations($own));
    }

    public function testBotCannotStampTheSamePlaneTwice(): void
    {
        self::assertFalse($this->stamp('Bearer '.$this->bot->getCode())['result']['isError'] ?? false);

        $data = $this->stamp('Bearer '.$this->bot->getCode());

        $this->assertToolError($data, 'This plane is already stamped by the participant.');
        self::assertCount(1, $this->participations());
    }

    public function testBotWithoutCountryCannotStamp(): void
    {
        $stateless = $this->participant(ParticipantType::Bot);

        $data = $this->stamp('Bearer '.$stateless->getCode());

        $this->assertToolError($data, 'A country is required to stamp a plane.');
        self::assertCount(0, $this->participations());
    }

    public function testUnknownPlaneIsAToolError(): void
    {
        $data = $this->stamp('Bearer '.$this->bot->getCode(), self::UNKNOWN_UUID);

        $this->assertToolError($data, 'Paper plane not found.');
        self::assertSame(0, static::getContainer()->get(PlaneVisitRepository::class)->count([]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedArguments(): iterable
    {
        yield 'missing uuid' => [[]];
        yield 'empty uuid' => [['uuid' => '']];
        yield 'not a uuid' => [['uuid' => 'not-a-uuid']];
        yield 'non-string uuid' => [['uuid' => 42]];
        yield 'chosen country' => [['uuid' => self::UNKNOWN_UUID, 'countryCode' => 'JP']];
        yield 'chosen emoji' => [['uuid' => self::UNKNOWN_UUID, 'emoji' => '💥']];
        yield 'chosen participant' => [['uuid' => self::UNKNOWN_UUID, 'participant' => self::UNKNOWN_UUID]];
        yield 'chosen color' => [['uuid' => self::UNKNOWN_UUID, 'color' => '#000000']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('malformedArguments')]
    public function testArgumentsOutsideTheSchemaAreRejected(array $arguments): void
    {
        $data = $this->mcp('tools/call', ['name' => 'stamp_paper_plane', 'arguments' => (object) $arguments], 'Bearer '.$this->bot->getCode(), ['HTTP_MCP_NAME' => 'stamp_paper_plane']);

        self::assertSame(-32602, $data['error']['code']);
        self::assertCount(0, $this->participations());
    }

    public function testNoCredentialsOrParticipantIdentifiersAreExposed(): void
    {
        $this->stamp('Bearer '.$this->bot->getCode());

        $content = (string) $this->client->getResponse()->getContent();
        foreach ([$this->bot, $this->author] as $participant) {
            self::assertStringNotContainsString($participant->getCode(), $content);
            self::assertStringNotContainsString($participant->getUuid()->toRfc4122(), $content);
        }
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    /** @param array<string, mixed> $data */
    private function assertToolError(array $data, string $message): void
    {
        self::assertResponseIsSuccessful();
        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertSame($message, $data['result']['content'][0]['text']);
    }

    /** @return list<PlaneVisit> */
    private function participations(?PaperPlane $plane = null): array
    {
        return $this->visits(PlaneVisitType::Participation, $plane);
    }

    /** @return list<PlaneVisit> */
    private function views(): array
    {
        return $this->visits(PlaneVisitType::View);
    }

    /** @return list<PlaneVisit> */
    private function visits(PlaneVisitType $type, ?PaperPlane $plane = null): array
    {
        $this->em->clear();

        return static::getContainer()->get(PlaneVisitRepository::class)->findBy(['plane' => ($plane ?? $this->plane)->getId(), 'type' => $type], ['id' => 'ASC']);
    }

    /** @return array<string, mixed> */
    private function stamp(?string $authorization, ?string $uuid = null): array
    {
        return $this->mcp(
            'tools/call',
            ['name' => 'stamp_paper_plane', 'arguments' => ['uuid' => $uuid ?? $this->plane->getUuid()->toRfc4122()]],
            $authorization,
            ['HTTP_MCP_NAME' => 'stamp_paper_plane'],
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
