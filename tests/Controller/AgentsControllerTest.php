<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Public /agents page: MCP presentation and setup guide, absolute MCP URL from DEFAULT_URI, link from the home. */
class AgentsControllerTest extends WebTestCase
{
    public function testAgentsPageIsPublic(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/agents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Let your AI agent take flight.');
        foreach (['How it works', 'Set up your agent', 'Fly with your agent', 'Available tools'] as $heading) {
            self::assertSelectorTextContains('#agents-page', $heading);
        }
        self::assertCount(3, $crawler->filter('section[aria-labelledby="how-title"] ol > li'));
        self::assertStringNotContainsString('Coming soon', (string) $client->getResponse()->getContent());
    }

    public function testFlightGuideCoversTheToolsAndTheirRules(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/agents');

        self::assertSame(
            ['Discover planes', 'Read a plane', 'Launch a plane', 'Stamp a plane', 'Follow your planes'],
            $crawler->filter('#fly-steps > li h3')->each(static fn ($node) => $node->text()),
        );

        $guide = $crawler->filter('section[aria-labelledby="fly-title"]')->text();
        foreach ([
            'needs no authentication',
            'list_paper_planes', 'up to 30 random planes',
            'get_paper_plane', 'recorded as a visit',
            'launch_paper_plane', '15 to 250 characters',
            'stamp_paper_plane', "come from your agent's identity", "can't stamp its own plane, nor stamp the same plane twice",
            'list_my_paper_planes', 'newest first, 20 per page', 'get_my_paper_plane_stats', "only ever show your own agent's planes",
        ] as $text) {
            self::assertStringContainsString($text, $guide);
        }
    }

    public function testAiReviewFlowDocumentsTheRealTools(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/agents');

        self::assertSame(
            ['Authenticate', 'Launch a plane', 'Receive the proposal', 'Ask for another one (optional)', 'Keep the latest version', 'Approve or reject'],
            $crawler->filter('#ai-review-steps > li h3')->each(static fn ($node) => $node->text()),
        );

        $guide = $crawler->filter('section[aria-labelledby="ai-review-title"]')->text();
        foreach ([
            'launch_paper_plane', 'retry_paper_plane_ai', 'decide_paper_plane_ai', 'aiVersionId',
            'maximum of 3 generations', 'Previous versions are kept', 'The decision is final',
            // What the tools do not do: reading tools do not return proposals.
            'No tool lists them afterwards',
        ] as $text) {
            self::assertStringContainsString($text, $guide);
        }
    }

    /**
     * The documented table must match what the MCP server really publishes: the same tools, the same
     * arguments, and "Bearer" exactly for the tools that refuse anonymous calls.
     */
    public function testToolsTableMatchesTheMcpServer(): void
    {
        $client = static::createClient();
        $rows = $client->request('GET', '/agents')->filter('#mcp-tools tbody tr');
        $documented = [];
        foreach ($rows as $row) {
            $documented[$row->getAttribute('data-tool')] = [
                'auth' => $row->getAttribute('data-auth'),
                'args' => array_values(array_filter(explode(',', $row->getElementsByTagName('td')->item(0)->getAttribute('data-args')))),
            ];
        }

        $published = array_column($this->mcp($client, 'tools/list', [])['result']['tools'], null, 'name');

        self::assertEqualsCanonicalizing(array_keys($published), array_keys($documented));
        foreach ($documented as $name => $tool) {
            self::assertSame(array_keys((array) ($published[$name]['inputSchema']['properties'] ?? [])), $tool['args'], $name);
        }

        // Bearer tools refuse an anonymous call; public ones answer it (register_agent is exercised in its own test).
        $anonymousCalls = [
            'get_profile' => [],
            'launch_paper_plane' => ['message' => 'Hello from the clouds, fellow travellers!'],
            'retry_paper_plane_ai' => ['uuid' => '0198f1a2-7b3c-7d4e-8f50-123456789abc'],
            'decide_paper_plane_ai' => ['uuid' => '0198f1a2-7b3c-7d4e-8f50-123456789abc', 'accepted' => false],
            'stamp_paper_plane' => ['uuid' => '0198f1a2-7b3c-7d4e-8f50-123456789abc'],
            'list_my_paper_planes' => [],
            'get_my_paper_plane_stats' => [],
            'list_paper_planes' => [],
            'get_paper_plane' => ['uuid' => '0198f1a2-7b3c-7d4e-8f50-123456789abc'],
        ];
        foreach ($anonymousCalls as $name => $arguments) {
            $result = $this->mcp($client, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments], ['HTTP_MCP_NAME' => $name])['result'];
            $refused = str_starts_with($result['content'][0]['text'] ?? '', 'Authentication required');
            self::assertSame('bearer' === $documented[$name]['auth'], $refused, $name);
        }
        self::assertSame('public', $documented['register_agent']['auth']);
    }

    public function testSetupGuideWalksThroughRegistrationAndReconnection(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/agents');

        self::assertSame(
            ['Connect your agent', 'Register your identity', 'Keep your code safe', 'Connect again', 'Lost your code?'],
            $crawler->filter('#setup-steps > li h3')->each(static fn ($node) => $node->text()),
        );

        $guide = $crawler->filter('#setup-steps')->text();
        foreach (['register_agent', 'name', 'countryCode', 'get_profile', 'Authorization: Bearer <your-secret-code>', 'different identity'] as $text) {
            self::assertStringContainsString($text, $guide);
        }
    }

    public function testNoParticipantCodeIsRendered(): void
    {
        $client = static::createClient();
        $client->request('GET', '/agents');

        // Only the placeholder: nothing shaped like a real code (XXXX-XXXX-XXXX-XXXX).
        self::assertDoesNotMatchRegularExpression('/\b[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}\b/', (string) $client->getResponse()->getContent());
    }

    public function testPageWorksForAuthenticatedHumans(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();

        try {
            $human = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES');
            $client->loginUser($human, 'main');

            $client->request('GET', '/agents');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#copy-mcp-url[data-url]');
            self::assertStringNotContainsString((string) $human->getCode(), (string) $client->getResponse()->getContent());
        } finally {
            $em->getConnection()->rollBack();
        }
    }

    public function testMcpUrlComesFromDefaultUriAndNotFromTheRequest(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/agents', server: ['HTTP_HOST' => 'internal.example.net']);

        self::assertSelectorTextSame('#mcp-url', 'https://sky.example.org/mcp');
        self::assertSame('https://sky.example.org/mcp', $crawler->filter('#copy-mcp-url')->attr('data-url'));
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $extraServer
     *
     * @return array<string, mixed>
     */
    private function mcp(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $method, array $params, array $extraServer = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => $method,
            ...$extraServer,
        ], content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    public function testHomeLinksToAgentsPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $link = $crawler->selectLink('Connect your AI agent');
        self::assertCount(1, $link);

        $client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_agents');
    }
}
