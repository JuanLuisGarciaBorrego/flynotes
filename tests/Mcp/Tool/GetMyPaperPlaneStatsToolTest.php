<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Manager\PlaneVisitManager;
use App\Repository\PaperPlaneRepository;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_my_paper_plane_stats` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28: the MCP
 * counterpart of GET /api/me/paper_planes/stats (see MyPaperPlaneStatsApiTest).
 */
class GetMyPaperPlaneStatsToolTest extends WebTestCase
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
        self::assertArrayHasKey('get_my_paper_plane_stats', $tools);
        $tool = $tools['get_my_paper_plane_stats'];
        // No arguments at all: the owner is the Bearer's bot.
        self::assertSame([], (array) ($tool['inputSchema']['properties'] ?? []));
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertTrue($tool['annotations']['idempotentHint']);
        self::assertFalse($tool['annotations']['destructiveHint']);
        self::assertSame(['totalPlanes', 'totalViews', 'totalCountries'], $tool['outputSchema']['required']);
        self::assertFalse($tool['outputSchema']['additionalProperties']);
    }

    public function testAnonymousCallIsRejected(): void
    {
        $result = $this->stats(null)['result'];

        self::assertTrue($result['isError']);
        self::assertSame(self::AUTH_ERROR, $result['content'][0]['text']);
        self::assertArrayNotHasKey('structuredContent', $result);
    }

    public function testHumanCodeIsRejectedByTheFirewall(): void
    {
        $human = $this->participant(ParticipantType::Human, 'ES');

        $this->stats('Bearer '.$human->getCode());

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString($human->getCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutPlanesEverythingIsZero(): void
    {
        $result = $this->stats('Bearer '.$this->bot->getCode())['result'];

        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['totalPlanes' => 0, 'totalViews' => 0, 'totalCountries' => 0], $result['structuredContent']);
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));
    }

    public function testTotalsCoverAllMyPlanesAndOnlyMine(): void
    {
        $this->seed($this->bot);

        $data = $this->stats('Bearer '.$this->bot->getCode())['result']['structuredContent'];

        // 22 planes (more than one page of list_my_paper_planes); 4 reads (repeated and anonymous ones count);
        // FR and JP (FR twice counts once, the stamp without country does not count).
        self::assertSame(['totalPlanes' => 22, 'totalViews' => 4, 'totalCountries' => 2], $data);
    }

    public function testSameMetricsAsTheHttpResourceForEquivalentData(): void
    {
        // HTTP serves humans, MCP serves bots: the same activity on a human's planes and on a bot's planes.
        $human = $this->participant(ParticipantType::Human, 'ES');
        $this->seed($human);
        $this->seed($this->bot);

        $mcp = $this->stats('Bearer '.$this->bot->getCode())['result']['structuredContent'];

        $this->client->loginUser($human, 'main');
        $this->client->request('GET', '/api/me/paper_planes/stats', server: ['HTTP_ACCEPT' => 'application/json']);
        $http = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertSame($http, $mcp);
    }

    public function testOtherParticipantsStatsAreNotReachable(): void
    {
        $other = $this->participant(ParticipantType::Bot, 'IT');
        $this->seed($other);

        $mine = $this->stats('Bearer '.$this->bot->getCode())['result']['structuredContent'];
        self::assertSame(['totalPlanes' => 0, 'totalViews' => 0, 'totalCountries' => 0], $mine);

        // No argument can name another participant.
        foreach ([['participant' => $other->getUuid()->toRfc4122()], ['author' => $other->getId()]] as $arguments) {
            self::assertSame(-32602, $this->stats('Bearer '.$this->bot->getCode(), $arguments)['error']['code']);
        }

        self::assertSame(22, $this->stats('Bearer '.$other->getCode())['result']['structuredContent']['totalPlanes']);
    }

    public function testReadingTheStatsChangesNothing(): void
    {
        $this->seed($this->bot);
        $visits = static::getContainer()->get(PlaneVisitRepository::class);
        $planes = static::getContainer()->get(PaperPlaneRepository::class);
        $before = [$visits->count([]), $planes->count([])];

        $first = $this->stats('Bearer '.$this->bot->getCode())['result']['structuredContent'];
        $second = $this->stats('Bearer '.$this->bot->getCode())['result']['structuredContent'];

        self::assertSame($first, $second);
        self::assertSame($before, [$visits->count([]), $planes->count([])]);
    }

    /** The SDK validates structuredContent against the declared outputSchema: a mismatch would be an isError result. */
    public function testResponseMatchesTheOutputSchema(): void
    {
        $this->seed($this->bot);
        $schema = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name')['get_my_paper_plane_stats']['outputSchema'];

        $result = $this->stats('Bearer '.$this->bot->getCode())['result'];

        self::assertFalse($result['isError'] ?? false);
        self::assertSame(array_keys($schema['properties']), array_keys($result['structuredContent']));
        self::assertContainsOnlyInt($result['structuredContent']);
    }

    /**
     * The scenario of MyPaperPlaneStatsApiTest::testTotalsCoverAllMyPlanesAndOnlyMine() for any author:
     * 22 planes, 4 reads, stamps from FR (twice), JP and one historical stamp without country, plus activity of
     * the author on someone else's plane, which is not theirs to count.
     */
    private function seed(Participant $author): void
    {
        $visits = static::getContainer()->get(PlaneVisitManager::class);
        $french = $this->participant(ParticipantType::Human, 'FR');
        $japanese = $this->participant(ParticipantType::Bot, 'JP');
        $stateless = $this->participant(ParticipantType::Human);

        $mine = [];
        for ($i = 0; $i < 22; ++$i) {
            $mine[] = $this->plane($author);
        }
        $visits->registerView($mine[0], $french);
        $visits->registerView($mine[0], $french);
        $visits->registerView($mine[1], null);
        $visits->registerView($mine[21], $japanese);
        $visits->registerParticipation($mine[0], $french);
        $visits->registerParticipation($mine[21], $french);
        $visits->registerParticipation($mine[21], $japanese);
        // PlaneVisitManager no longer lets a participant without country stamp: stored directly, as older data.
        $this->em->persist(new PlaneVisit()->setPlane($mine[2])->setParticipant($stateless)->setType(PlaneVisitType::Participation)->setCountryCode(null)->setEmoji(null)->setMessage(null));
        $this->em->flush();

        $theirs = $this->plane($french);
        $visits->registerView($theirs, $author);
        if (null !== $author->getCountryCode()) {
            $visits->registerParticipation($theirs, $author);
        }
    }

    private function plane(Participant $author): PaperPlane
    {
        return static::getContainer()->get(PaperPlaneManager::class)->create($author, 'Un avión de papel cualquiera');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function stats(?string $authorization, array $arguments = []): array
    {
        return $this->mcp(
            'tools/call',
            ['name' => 'get_my_paper_plane_stats', 'arguments' => (object) $arguments],
            $authorization,
            ['HTTP_MCP_NAME' => 'get_my_paper_plane_stats'],
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
