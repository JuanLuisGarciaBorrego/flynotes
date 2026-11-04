<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Schemas every MCP client can read: nullable values are `anyOf: [{type: string}, {type: null}]`, never
 * `type: ["string", "null"]`, which some clients and model providers reject. Nullable is not optional: those
 * properties stay required, and the SDK still validates null results against the outputSchema.
 */
class ToolSchemaCompatibilityTest extends WebTestCase
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

    public function testNoSchemaUsesATypeArray(): void
    {
        $tools = $this->mcp('tools/list', [])['result']['tools'];

        $names = array_column($tools, 'name');
        sort($names);
        self::assertSame([
            'decide_paper_plane_ai',
            'get_my_paper_plane_stats',
            'get_paper_plane',
            'get_profile',
            'launch_paper_plane',
            'list_my_paper_planes',
            'list_paper_planes',
            'register_agent',
            'retry_paper_plane_ai',
            'stamp_paper_plane',
        ], $names, 'A tool added or lost has to be a decision.');

        foreach ($tools as $tool) {
            foreach (['inputSchema', 'outputSchema'] as $kind) {
                $this->walk($tool[$kind] ?? [], $tool['name'].'.'.$kind, static function (array $node, string $path): void {
                    self::assertFalse(\is_array($node['type'] ?? null), $path.' declares "type" as an array.');
                });
            }
        }
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> tool, path to the object, nullable properties */
    public static function nullableProperties(): iterable
    {
        yield 'register_agent' => ['register_agent', [], ['emoji']];
        yield 'get_profile' => ['get_profile', [], ['emoji', 'countryCode']];
        yield 'launch_paper_plane' => ['launch_paper_plane', [], ['aiMessage', 'aiVersionId', 'aiTone', 'aiStatus', 'authorEmoji']];
        yield 'retry_paper_plane_ai' => ['retry_paper_plane_ai', [], ['aiMessage', 'aiVersionId', 'aiTone']];
        yield 'decide_paper_plane_ai' => ['decide_paper_plane_ai', [], ['aiMessage', 'aiVersionId', 'aiTone']];
        yield 'get_paper_plane' => ['get_paper_plane', [], ['authorEmoji', 'authorCountryCode', 'createdAt']];
        yield 'get_paper_plane stamps' => ['get_paper_plane', ['stamps'], ['countryCode', 'emoji', 'createdAt']];
        yield 'stamp_paper_plane' => ['stamp_paper_plane', [], ['countryCode', 'countryName', 'emoji', 'createdAt']];
        yield 'list_my_paper_planes planes' => ['list_my_paper_planes', ['planes'], ['createdAt']];
        yield 'list_my_paper_planes stamps' => ['list_my_paper_planes', ['planes', 'stamps'], ['countryCode']];
    }

    /**
     * @param list<string> $path
     * @param list<string> $nullable
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nullableProperties')]
    public function testNullablePropertiesUseAnyOfAndStayRequired(string $name, array $path, array $nullable): void
    {
        $tools = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name');
        $schema = $tools[$name]['outputSchema'];
        foreach ($path as $property) {
            $schema = $schema['properties'][$property]['items'];
        }

        foreach ($nullable as $property) {
            $branches = $schema['properties'][$property]['anyOf'] ?? null;
            self::assertIsArray($branches, $name.'.'.$property);
            self::assertSame(['string', 'null'], array_column($branches, 'type'), $name.'.'.$property);
            self::assertContains($property, $schema['required'], $name.'.'.$property.' must stay required.');
        }
        self::assertFalse($schema['additionalProperties']);
    }

    /** Neither of the two AI tools lets the caller choose who acts, and each says what it can do. */
    public function testTheAiToolsOnlyTakeTheirOwnArguments(): void
    {
        $tools = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name');

        $retry = $tools['retry_paper_plane_ai'];
        self::assertSame(['uuid'], array_keys($retry['inputSchema']['properties']));
        self::assertSame(['uuid'], $retry['inputSchema']['required']);
        self::assertFalse($retry['inputSchema']['additionalProperties']);
        self::assertFalse($retry['annotations']['readOnlyHint']);
        self::assertTrue($retry['annotations']['openWorldHint'], 'It calls the model.');

        $decide = $tools['decide_paper_plane_ai'];
        self::assertSame(['uuid', 'accepted', 'versionId'], array_keys($decide['inputSchema']['properties']));
        self::assertSame(['uuid', 'accepted'], $decide['inputSchema']['required']);
        self::assertFalse($decide['inputSchema']['additionalProperties']);
        self::assertFalse($decide['annotations']['readOnlyHint']);
        self::assertFalse($decide['annotations']['openWorldHint'], 'It generates nothing.');
        self::assertSame(['accepted', 'rejected'], $decide['outputSchema']['properties']['aiStatus']['enum']);
    }

    public function testFormatsAndDescriptionsAreKept(): void
    {
        $tools = array_column($this->mcp('tools/list', [])['result']['tools'], null, 'name');
        $detail = $tools['get_paper_plane']['outputSchema']['properties'];

        self::assertSame([['type' => 'string', 'format' => 'date-time'], ['type' => 'null']], $detail['createdAt']['anyOf']);
        self::assertSame('ISO 3166-1 alpha-2 code', $detail['authorCountryCode']['description']);
        self::assertSame('ISO 3166-1 alpha-2 code', $tools['get_profile']['outputSchema']['properties']['countryCode']['description']);
    }

    /** Null values pass the SDK's outputSchema validation: an invalid structuredContent would be an isError result. */
    public function testNullResultsStillValidate(): void
    {
        $participants = static::getContainer()->get(ParticipantManager::class);
        $bot = $participants->create(ParticipantType::Bot, 'Bot sin país');
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($bot, 'Un avión de papel cualquiera');
        // A stamp without country nor emoji, as older data may have (PlaneVisitManager no longer allows it).
        $this->em->persist(new PlaneVisit()->setPlane($plane)->setParticipant($participants->create(ParticipantType::Human, 'Sin país'))->setType(PlaneVisitType::Participation)->setCountryCode(null)->setEmoji(null)->setMessage(null));
        $this->em->flush();
        $bearer = 'Bearer '.$bot->getCode();

        $profile = $this->call('get_profile', [], $bearer)['result'];
        self::assertFalse($profile['isError'] ?? false);
        self::assertNull($profile['structuredContent']['countryCode']);

        $detail = $this->call('get_paper_plane', ['uuid' => $plane->getUuid()->toRfc4122()], $bearer)['result'];
        self::assertFalse($detail['isError'] ?? false);
        self::assertNull($detail['structuredContent']['authorCountryCode']);
        self::assertNull($detail['structuredContent']['stamps'][0]['countryCode']);
        self::assertNull($detail['structuredContent']['stamps'][0]['emoji']);

        $mine = $this->call('list_my_paper_planes', [], $bearer)['result'];
        self::assertFalse($mine['isError'] ?? false);
        self::assertNull($mine['structuredContent']['planes'][0]['stamps'][0]['countryCode']);
    }

    /** @param callable(array<string, mixed>, string): void $visit */
    private function walk(mixed $node, string $path, callable $visit, bool $isPropertyMap = false): void
    {
        if (!\is_array($node)) {
            return;
        }
        // In a `properties` map the keys are property names (e.g. the profile's `type`), not keywords.
        if (!$isPropertyMap) {
            $visit($node, $path);
        }
        foreach ($node as $key => $child) {
            $this->walk($child, $path.'.'.$key, $visit, !$isPropertyMap && 'properties' === $key);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $arguments, string $authorization): array
    {
        return $this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $authorization, ['HTTP_MCP_NAME' => $name]);
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
}
