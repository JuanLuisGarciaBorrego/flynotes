<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Helper\StampColorHelper;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Manager\PlaneVisitManager;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `list_my_paper_planes` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28: the MCP
 * counterpart of GET /api/me/paper_planes (see MyPaperPlaneApiTest).
 */
class ListMyPaperPlanesToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';
    private const string AUTH_ERROR = 'Authentication required: call this tool with "Authorization: Bearer <your bot code>".';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $bot;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // A distinct "origin" per test so the persisted rate limiter state never leaks between tests/runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->bot = $this->participant(ParticipantType::Bot, 'PT');
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
        $tools = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('list_my_paper_planes', $tools);
        $tool = $tools['list_my_paper_planes'];
        // Only the page: no argument can choose the owner.
        self::assertSame(['page'], array_keys($tool['inputSchema']['properties']));
        self::assertEquals(['type' => 'integer', 'description' => 'Page number, starting at 1.', 'default' => 1, 'minimum' => 1], $tool['inputSchema']['properties']['page']);
        self::assertArrayNotHasKey('required', $tool['inputSchema']);
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertTrue($tool['annotations']['idempotentHint']);
        self::assertFalse($tool['annotations']['destructiveHint']);
        self::assertFalse($tool['outputSchema']['additionalProperties']);
    }

    public function testAnonymousCallIsRejected(): void
    {
        $this->plane($this->bot, 'Mi primer avión de papel', '2026-01-01 10:00');

        $result = $this->list(null)['result'];

        self::assertTrue($result['isError']);
        self::assertSame(self::AUTH_ERROR, $result['content'][0]['text']);
        self::assertArrayNotHasKey('structuredContent', $result);
    }

    public function testHumanCodeIsRejectedByTheFirewall(): void
    {
        $human = $this->participant(ParticipantType::Human, 'ES');
        $this->plane($human, 'El avión de una humana', '2026-01-01 10:00');

        $this->list('Bearer '.$human->getCode());

        self::assertResponseStatusCodeSame(401);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($human->getCode(), $content);
        self::assertStringNotContainsString('El avión de una humana', $content);
    }

    public function testReturnsOnlyMyPlanesNewestFirstWithCounts(): void
    {
        $older = $this->plane($this->bot, 'Mi primer avión de papel', '2026-01-01 10:00');
        $newer = $this->plane($this->bot, 'Mi segundo avión de papel', '2026-11-02 18:10');

        $french = $this->participant(ParticipantType::Human, 'FR');
        $japanese = $this->participant(ParticipantType::Bot, 'JP');
        $theirs = $this->plane($french, 'Un avión que no es mío', '2026-11-02 19:00');
        $visits = static::getContainer()->get(PlaneVisitManager::class);
        $visits->registerView($newer, $french);
        $visits->registerView($newer, null);
        $visits->registerView($older, $japanese);
        $visits->registerView($theirs, $this->bot);
        $stamp = $visits->registerParticipation($newer, $french);
        $visits->registerParticipation($newer, $japanese);
        $visits->registerParticipation($theirs, $this->bot);

        $result = $this->list('Bearer '.$this->bot->getCode())['result'];

        self::assertFalse($result['isError'] ?? false);
        $data = $result['structuredContent'];
        self::assertSame($data, json_decode($result['content'][0]['text'], true));
        self::assertSame(1, $data['page']);
        self::assertSame(PaperPlaneManager::OWN_PER_PAGE, $data['itemsPerPage']);
        self::assertSame(2, $data['totalItems']);
        self::assertSame([$newer->getUuid()->toRfc4122(), $older->getUuid()->toRfc4122()], array_column($data['planes'], 'uuid'));
        self::assertSame([
            'uuid' => $newer->getUuid()->toRfc4122(),
            'message' => 'Mi segundo avión de papel',
            'createdAt' => new \DateTimeImmutable('2026-11-02 18:10')->format(\DATE_ATOM),
            'viewCount' => 2,
            'stampCount' => 2,
            'stamps' => [
                [
                    'uuid' => $stamp->getUuid()->toRfc4122(),
                    'countryCode' => 'FR',
                    'color' => static::getContainer()->get(StampColorHelper::class)->colorFor($stamp->getUuid()),
                ],
                $data['planes'][0]['stamps'][1],
            ],
        ], $data['planes'][0]);
        self::assertSame('JP', $data['planes'][0]['stamps'][1]['countryCode']);
        self::assertSame([1, 0], [$data['planes'][1]['viewCount'], $data['planes'][1]['stampCount']]);
    }

    public function testOtherParticipantsPlanesAndIdentifiersAreNotShown(): void
    {
        $this->plane($this->bot, 'Mi primer avión de papel', '2026-01-01 10:00');
        $other = $this->participant(ParticipantType::Bot, 'IT');
        $hers = $this->plane($other, 'El avión de otro agente', '2026-02-01 10:00');

        $mine = $this->list('Bearer '.$this->bot->getCode());
        $content = (string) $this->client->getResponse()->getContent();

        self::assertSame(1, $mine['result']['structuredContent']['totalItems']);
        self::assertStringNotContainsString('El avión de otro agente', $content);
        foreach ([$this->bot, $other] as $participant) {
            self::assertStringNotContainsString($participant->getCode(), $content);
            self::assertStringNotContainsString($participant->getUuid()->toRfc4122(), $content);
        }
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));

        // The other agent gets its own planes with its own Bearer.
        $theirs = $this->list('Bearer '.$other->getCode())['result']['structuredContent'];
        self::assertSame([$hers->getUuid()->toRfc4122()], array_column($theirs['planes'], 'uuid'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedArguments(): iterable
    {
        yield 'chosen owner' => [['participant' => '0198f1a2-7b3c-7d4e-8f50-123456789abc']];
        yield 'chosen author' => [['page' => 1, 'author' => 1]];
        yield 'chosen page size' => [['itemsPerPage' => 100]];
        yield 'page zero' => [['page' => 0]];
        yield 'negative page' => [['page' => -1]];
        yield 'non-integer page' => [['page' => 'two']];
    }

    /** @param array<string, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedArguments')]
    public function testArgumentsOutsideTheSchemaAreRejected(array $arguments): void
    {
        $data = $this->list('Bearer '.$this->bot->getCode(), $arguments);

        self::assertSame(-32602, $data['error']['code']);
    }

    public function testIsPaginatedLikeTheHttpCollection(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->plane($this->bot, \sprintf('Avión de papel número %02d', $i), \sprintf('2026-01-%02d 10:00', $i));
        }

        $first = $this->list('Bearer '.$this->bot->getCode())['result']['structuredContent'];
        self::assertSame(25, $first['totalItems']);
        self::assertCount(20, $first['planes']);
        self::assertSame('Avión de papel número 25', $first['planes'][0]['message']);

        $second = $this->list('Bearer '.$this->bot->getCode(), ['page' => 2])['result']['structuredContent'];
        self::assertSame(2, $second['page']);
        self::assertSame(25, $second['totalItems']);
        self::assertSame(['Avión de papel número 05', 'Avión de papel número 01'], [$second['planes'][0]['message'], $second['planes'][4]['message']]);
        self::assertCount(5, $second['planes']);
        self::assertSame([], array_intersect(array_column($first['planes'], 'uuid'), array_column($second['planes'], 'uuid')));

        $beyond = $this->list('Bearer '.$this->bot->getCode(), ['page' => 3])['result'];
        self::assertFalse($beyond['isError'] ?? false);
        self::assertSame([], $beyond['structuredContent']['planes']);
    }

    public function testListingDoesNotRegisterVisits(): void
    {
        $plane = $this->plane($this->bot, 'Mi primer avión de papel', '2026-01-01 10:00');
        static::getContainer()->get(PlaneVisitManager::class)->registerView($plane, $this->participant(ParticipantType::Human, 'FR'));
        $visits = static::getContainer()->get(PlaneVisitRepository::class);
        $before = $visits->count([]);

        $this->list('Bearer '.$this->bot->getCode());
        $data = $this->list('Bearer '.$this->bot->getCode())['result']['structuredContent'];

        self::assertSame(1, $data['planes'][0]['viewCount']);
        self::assertSame($before, $visits->count([]));
    }

    /** The SDK validates structuredContent against the declared outputSchema: a mismatch would be an isError result. */
    public function testResponseMatchesTheOutputSchema(): void
    {
        $plane = $this->plane($this->bot, 'Mi primer avión de papel', '2026-01-01 10:00');
        static::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $this->participant(ParticipantType::Human, 'FR'));
        $schema = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name')['list_my_paper_planes']['outputSchema'];

        $result = $this->list('Bearer '.$this->bot->getCode())['result'];

        self::assertFalse($result['isError'] ?? false);
        $data = $result['structuredContent'];
        self::assertSame($schema['required'], array_keys($data));
        self::assertSame(array_keys($schema['properties']), array_keys($data));
        $item = $schema['properties']['planes']['items'];
        self::assertSame($item['required'], array_keys($data['planes'][0]));
        self::assertSame($item['properties']['stamps']['items']['required'], array_keys($data['planes'][0]['stamps'][0]));
    }

    private function plane(Participant $author, string $message, string $createdAt): PaperPlane
    {
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($author, $message);
        $plane->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->em->flush();

        return $plane;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function list(?string $authorization, array $arguments = []): array
    {
        return $this->mcp(
            'tools/call',
            ['name' => 'list_my_paper_planes', 'arguments' => (object) $arguments],
            $authorization,
            ['HTTP_MCP_NAME' => 'list_my_paper_planes'],
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
