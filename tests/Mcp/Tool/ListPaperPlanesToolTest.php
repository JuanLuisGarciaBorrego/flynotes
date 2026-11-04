<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `list_paper_planes` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28.
 */
class ListPaperPlanesToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
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
        self::assertArrayHasKey('list_paper_planes', $tools);

        $tool = $tools['list_paper_planes'];
        // No limit, page or filter: the feed size is fixed by the server.
        self::assertSame([], (array) ($tool['inputSchema']['properties'] ?? []));
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertSame(['uuids'], array_keys($tool['outputSchema']['properties']));
        self::assertSame(PaperPlaneManager::RANDOM_LIMIT, $tool['outputSchema']['properties']['uuids']['maxItems']);
    }

    public function testAnonymousAgentGetsAtMostTheLimitOfUuidsAndNoVisitIsRecorded(): void
    {
        $this->seed(PaperPlaneManager::RANDOM_LIMIT + 5);
        $visits = $this->countVisits();

        $data = $this->list(null);

        self::assertResponseIsSuccessful();
        $result = $data['result'];
        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['uuids'], array_keys($result['structuredContent']));

        $uuids = $result['structuredContent']['uuids'];
        self::assertCount(PaperPlaneManager::RANDOM_LIMIT, $uuids);
        self::assertCount(PaperPlaneManager::RANDOM_LIMIT, array_unique($uuids));
        foreach ($uuids as $uuid) {
            self::assertTrue(Uuid::isValid($uuid));
        }
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));

        // Only UUIDs: no message or author leaks into the feed.
        self::assertStringNotContainsString('Mensaje', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Autor', (string) $this->client->getResponse()->getContent());
        self::assertSame($visits, $this->countVisits());
    }

    public function testBotGetsTheSameFeed(): void
    {
        $this->seed(3);
        $bot = $this->participant(ParticipantType::Bot);

        $data = $this->list('Bearer '.$bot->getCode());

        self::assertFalse($data['result']['isError'] ?? false);
        self::assertGreaterThanOrEqual(3, \count($data['result']['structuredContent']['uuids']));
        self::assertStringNotContainsString($bot->getCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testNoPlanesGiveAnEmptyList(): void
    {
        // Inside the test transaction: rolled back in tearDown().
        $this->em->createQuery(sprintf('DELETE FROM %s', PlaneVisit::class))->execute();
        $this->em->createQuery(sprintf('DELETE FROM %s', PaperPlane::class))->execute();

        $data = $this->list(null);

        self::assertFalse($data['result']['isError'] ?? false);
        self::assertSame(['uuids' => []], $data['result']['structuredContent']);
    }

    public function testArgumentsAreRejected(): void
    {
        $this->seed(1);

        $data = $this->list(null, ['limit' => 5]);

        self::assertSame(-32602, $data['error']['code']);
    }

    private function seed(int $planes): void
    {
        $author = $this->participant(ParticipantType::Human);
        $manager = static::getContainer()->get(PaperPlaneManager::class);
        for ($i = 0; $i < $planes; ++$i) {
            $manager->create($author, 'Mensaje de prueba '.$i);
        }
    }

    private function countVisits(): int
    {
        return static::getContainer()->get(PlaneVisitRepository::class)->count([]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function list(?string $authorization, array $arguments = []): array
    {
        return $this->mcp('tools/call', ['name' => 'list_paper_planes', 'arguments' => (object) $arguments], $authorization, ['HTTP_MCP_NAME' => 'list_paper_planes']);
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
        return static::getContainer()->get(ParticipantManager::class)->create($type, 'Autor '.$type->value, $countryCode);
    }
}
