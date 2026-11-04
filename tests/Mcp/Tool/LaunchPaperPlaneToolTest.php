<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\PaperPlaneType;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneAiManager;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Repository\PaperPlaneRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * `launch_paper_plane` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28.
 */
class LaunchPaperPlaneToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';
    private const string MESSAGE = 'Hello from a paper plane bot!';
    private const array OUTPUT_FIELDS = ['uuid', 'message', 'aiMessage', 'aiVersionId', 'aiTone', 'aiStatus', 'aiAttempts', 'aiAttemptsRemaining', 'authorName', 'authorEmoji'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // A distinct "origin" per test so the persisted rate limiter state never leaks between tests/runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
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
        self::assertArrayHasKey('launch_paper_plane', $tools);
        // Native tools still share the same server.
        self::assertArrayHasKey('register_agent', $tools);
        self::assertArrayHasKey('get_profile', $tools);

        $tool = $tools['launch_paper_plane'];
        // Only the message: the author, UUID, country and type are never client input.
        self::assertSame(['message'], array_keys($tool['inputSchema']['properties']));
        self::assertSame(['message'], $tool['inputSchema']['required']);
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        // The limits come from the same constants as the HTTP constraints.
        $messageSchema = $tool['inputSchema']['properties']['message'];
        self::assertSame('string', $messageSchema['type']);
        self::assertSame(PaperPlane::MESSAGE_MIN_LENGTH, $messageSchema['minLength']);
        self::assertSame(PaperPlane::MESSAGE_MAX_LENGTH, $messageSchema['maxLength']);
        self::assertStringContainsString('between 15 and 250 characters', $tool['description']);
        self::assertFalse($tool['annotations']['readOnlyHint']);
        self::assertSame(self::OUTPUT_FIELDS, array_keys($tool['outputSchema']['properties']));
    }

    public function testBotLaunchesAPlaneAuthoredByItself(): void
    {
        $this->stubAgent(new \RuntimeException('DeepSeek is down'));
        $bot = $this->participant(ParticipantType::Bot, 'ES');
        $this->em->clear();

        $data = $this->launch('Bearer '.$bot->getCode(), ['message' => '  '.self::MESSAGE.' ']);

        self::assertResponseIsSuccessful();
        $result = $data['result'];
        self::assertFalse($result['isError'] ?? false);

        $output = $result['structuredContent'];
        self::assertSame(self::OUTPUT_FIELDS, array_keys($output));
        self::assertTrue(Uuid::isValid($output['uuid']));
        self::assertSame(self::MESSAGE, $output['message']);
        // The generation failed: the plane flies anyway, with no proposal and no generation counted.
        self::assertNull($output['aiMessage']);
        self::assertNull($output['aiVersionId']);
        self::assertNull($output['aiTone']);
        self::assertNull($output['aiStatus']);
        self::assertSame(0, $output['aiAttempts']);
        self::assertSame(PaperPlane::AI_MAX_ATTEMPTS, $output['aiAttemptsRemaining']);
        self::assertSame($bot->getName(), $output['authorName']);
        self::assertSame($bot->getEmoji(), $output['authorEmoji']);
        self::assertSame($output, json_decode($result['content'][0]['text'], true));

        $this->em->clear();
        $plane = static::getContainer()->get(PaperPlaneRepository::class)->findOneBy(['uuid' => Uuid::fromString($output['uuid'])]);
        self::assertInstanceOf(PaperPlane::class, $plane);
        self::assertSame($bot->getId(), $plane->getCreatedBy()?->getId());
        self::assertSame(PaperPlaneType::Custom, $plane->getType());
        self::assertSame(self::MESSAGE, $plane->getMessage());
    }

    /** The whole flow with only what the tools answer: the first proposal can be approved straight from the launch. */
    public function testTheFirstProposalCanBeApprovedWithTheLaunchAnswer(): void
    {
        $this->stubAgent(['text' => 'Primera versión', 'tone' => 'Juguetón']);
        $bot = $this->participant(ParticipantType::Bot, 'ES');
        $bearer = 'Bearer '.$bot->getCode();

        $launched = $this->launch($bearer, ['message' => self::MESSAGE])['result'];

        self::assertFalse($launched['isError'] ?? false);
        $output = $launched['structuredContent'];
        self::assertSame('Primera versión', $output['aiMessage']);
        self::assertSame('Juguetón', $output['aiTone']);
        self::assertSame('pending', $output['aiStatus']);
        self::assertSame(1, $output['aiAttempts']);
        self::assertSame(PaperPlane::AI_MAX_ATTEMPTS - 1, $output['aiAttemptsRemaining']);
        self::assertTrue(Uuid::isValid((string) $output['aiVersionId']));

        $decided = $this->tool('decide_paper_plane_ai', $bearer, ['uuid' => $output['uuid'], 'accepted' => true, 'versionId' => $output['aiVersionId']])['result'];

        self::assertFalse($decided['isError'] ?? false);
        self::assertSame('accepted', $decided['structuredContent']['aiStatus']);
        self::assertSame($output['aiVersionId'], $decided['structuredContent']['aiVersionId']);
        self::assertSame('Primera versión', $decided['structuredContent']['aiMessage']);
    }

    /** A retry replaces the version, only the latest can be approved and the decision closes the flow, as /agents documents. */
    public function testOnlyTheLatestVersionCanBeApprovedAfterARetryAndTheDecisionIsFinal(): void
    {
        $this->stubAgent(['text' => 'Una versión', 'tone' => 'Juguetón']);
        $bearer = 'Bearer '.$this->participant(ParticipantType::Bot, 'ES')->getCode();
        $first = $this->launch($bearer, ['message' => self::MESSAGE])['result']['structuredContent'];

        $second = $this->tool('retry_paper_plane_ai', $bearer, ['uuid' => $first['uuid']])['result']['structuredContent'];
        self::assertNotSame($first['aiVersionId'], $second['aiVersionId']);
        self::assertSame(2, $second['aiAttempts']);

        self::assertTrue($this->tool('decide_paper_plane_ai', $bearer, ['uuid' => $first['uuid'], 'accepted' => true, 'versionId' => $first['aiVersionId']])['result']['isError']);

        $decided = $this->tool('decide_paper_plane_ai', $bearer, ['uuid' => $first['uuid'], 'accepted' => true, 'versionId' => $second['aiVersionId']])['result'];
        self::assertSame($second['aiVersionId'], $decided['structuredContent']['aiVersionId']);

        self::assertTrue($this->tool('retry_paper_plane_ai', $bearer, ['uuid' => $first['uuid']])['result']['isError']);
        self::assertTrue($this->tool('decide_paper_plane_ai', $bearer, ['uuid' => $first['uuid'], 'accepted' => false])['result']['isError']);
    }

    /** After a failed launch the bot can still ask for a proposal, as /agents documents. */
    public function testAFailedGenerationCanBeRetried(): void
    {
        $this->stubAgent(new \RuntimeException('DeepSeek is down'), ['text' => 'Por fin una versión', 'tone' => 'Juguetón']);
        $bearer = 'Bearer '.$this->participant(ParticipantType::Bot, 'ES')->getCode();
        $launched = $this->launch($bearer, ['message' => self::MESSAGE])['result']['structuredContent'];
        self::assertNull($launched['aiStatus']);

        $retried = $this->tool('retry_paper_plane_ai', $bearer, ['uuid' => $launched['uuid']])['result'];

        self::assertFalse($retried['isError'] ?? false);
        self::assertSame('Por fin una versión', $retried['structuredContent']['aiMessage']);
        self::assertSame(1, $retried['structuredContent']['aiAttempts']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function aiTools(): iterable
    {
        yield 'retry' => ['retry_paper_plane_ai', []];
        yield 'accept' => ['decide_paper_plane_ai', ['accepted' => true, 'versionId' => '0198f1a2-7b3c-7d4e-8f50-123456789abc']];
        yield 'reject' => ['decide_paper_plane_ai', ['accepted' => false]];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('aiTools')]
    public function testOnlyTheAuthorBotCanUseTheAiTools(string $name, array $arguments): void
    {
        $this->stubAgent(['text' => 'Primera versión', 'tone' => 'Juguetón']);
        $plane = $this->launch('Bearer '.$this->participant(ParticipantType::Bot, 'ES')->getCode(), ['message' => self::MESSAGE])['result']['structuredContent'];
        $arguments['uuid'] = $plane['uuid'];

        $anonymous = $this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments], null, ['HTTP_MCP_NAME' => $name])['result'];
        self::assertTrue($anonymous['isError']);
        self::assertStringContainsString('Authentication required', $anonymous['content'][0]['text']);

        $other = $this->tool($name, 'Bearer '.$this->participant(ParticipantType::Bot, 'FR')->getCode(), $arguments)['result'];
        self::assertTrue($other['isError']);
        self::assertStringContainsString('Only the author', $other['content'][0]['text']);

        $this->tool($name, 'Bearer '.$this->participant(ParticipantType::Human, 'FR')->getCode(), $arguments);
        self::assertResponseStatusCodeSame(401);

        $this->em->clear();
        $stored = static::getContainer()->get(PaperPlaneRepository::class)->findOneBy(['uuid' => Uuid::fromString($plane['uuid'])]);
        self::assertSame(1, $stored->getAiAttempts());
        self::assertSame('pending', $stored->getAiStatus()?->value);
    }

    public function testAcceptingNeedsAValidVersionAndArgumentsCannotChooseAnything(): void
    {
        $this->stubAgent(['text' => 'Primera versión', 'tone' => 'Juguetón']);
        $bearer = 'Bearer '.$this->participant(ParticipantType::Bot, 'ES')->getCode();
        $uuid = $this->launch($bearer, ['message' => self::MESSAGE])['result']['structuredContent']['uuid'];

        foreach ([['accepted' => true], ['accepted' => true, 'versionId' => 'not-a-uuid']] as $arguments) {
            $result = $this->tool('decide_paper_plane_ai', $bearer, ['uuid' => $uuid, ...$arguments])['result'];
            self::assertTrue($result['isError']);
            self::assertStringContainsString('valid versionId', $result['content'][0]['text']);
        }

        self::assertSame(-32602, $this->tool('retry_paper_plane_ai', $bearer, ['uuid' => $uuid, 'participant' => $uuid])['error']['code']);
        self::assertSame(-32602, $this->tool('decide_paper_plane_ai', $bearer, ['uuid' => $uuid, 'accepted' => false, 'author' => $uuid])['error']['code']);
        self::assertSame(-32602, $this->tool('retry_paper_plane_ai', $bearer, ['uuid' => 'not-a-uuid'])['error']['code']);
    }

    public function testResponseCarriesNoCredentialsAndNoSession(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        $this->launch('Bearer '.$bot->getCode(), ['message' => self::MESSAGE]);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($bot->getCode(), $content);
        self::assertStringNotContainsString('"code"', $content);
        self::assertStringNotContainsString($bot->getUuid()->toRfc4122(), $content);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    public function testAnonymousCallIsRejected(): void
    {
        $before = $this->countPlanes();

        $data = $this->launch(null, ['message' => self::MESSAGE]);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertStringContainsString('Authentication required', $data['result']['content'][0]['text']);
        self::assertSame($before, $this->countPlanes());
    }

    public function testHumanCodeIsRejectedByTheFirewall(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $before = $this->countPlanes();

        $this->launch('Bearer '.$human->getCode(), ['message' => self::MESSAGE]);

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString($human->getCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->countPlanes());
    }

    /**
     * Messages within the advertised raw length that the trimmed constraints of ApiResource\PaperPlane still
     * refuse, as the HTTP API does: they reach the tool and come back as tool errors.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidMessages(): iterable
    {
        yield 'blank' => [str_repeat(' ', PaperPlane::MESSAGE_MIN_LENGTH + 5), 'message: This value should not be blank.'];
        yield 'blank with tabs and newlines' => [str_repeat(" \t\n", PaperPlane::MESSAGE_MIN_LENGTH), 'message: This value should not be blank.'];
        yield 'too short once trimmed' => ['   '.str_repeat('a', PaperPlane::MESSAGE_MIN_LENGTH - 1).'   ', 'message: This value is too short.'];
    }

    #[DataProvider('invalidMessages')]
    public function testInvalidMessagesAreToolErrors(string $message, string $expected): void
    {
        $bot = $this->participant(ParticipantType::Bot);
        $before = $this->countPlanes();

        $data = $this->launch('Bearer '.$bot->getCode(), ['message' => $message]);

        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertStringContainsString($expected, $data['result']['content'][0]['text']);
        self::assertSame($before, $this->countPlanes());
    }

    /**
     * The SDK checks minLength/maxLength on the raw string before the tool runs, so these never reach the
     * constraints. A padded message longer than the maximum is refused here even though HTTP, which trims
     * first, would accept it.
     *
     * @return iterable<string, array{string}>
     */
    public static function messagesOutsideTheAdvertisedLength(): iterable
    {
        yield 'empty' => [''];
        yield 'one below the minimum' => [str_repeat('a', PaperPlane::MESSAGE_MIN_LENGTH - 1)];
        yield 'one above the maximum' => [str_repeat('a', PaperPlane::MESSAGE_MAX_LENGTH + 1)];
        yield 'maximum plus padding' => [' '.str_repeat('a', PaperPlane::MESSAGE_MAX_LENGTH).' '];
    }

    #[DataProvider('messagesOutsideTheAdvertisedLength')]
    public function testMessagesOutsideTheAdvertisedLengthAreInvalidParams(string $message): void
    {
        $bot = $this->participant(ParticipantType::Bot);
        $before = $this->countPlanes();

        $data = $this->launch('Bearer '.$bot->getCode(), ['message' => $message]);

        self::assertSame(-32602, $data['error']['code']);
        self::assertStringContainsString('message', $data['error']['message']);
        self::assertSame($before, $this->countPlanes());
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedMessages(): iterable
    {
        $minimum = str_repeat('a', PaperPlane::MESSAGE_MIN_LENGTH);
        $maximum = str_repeat('a', PaperPlane::MESSAGE_MAX_LENGTH);

        yield 'minimum' => [$minimum, $minimum];
        yield 'maximum' => [$maximum, $maximum];
        yield 'minimum with padding, stored trimmed' => ['  '.$minimum.'  ', $minimum];
    }

    #[DataProvider('acceptedMessages')]
    public function testMessagesWithinTheLimitsAreAccepted(string $message, string $stored): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        $data = $this->launch('Bearer '.$bot->getCode(), ['message' => $message]);

        self::assertFalse($data['result']['isError'] ?? false);
        self::assertSame($stored, $data['result']['structuredContent']['message']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedArguments(): iterable
    {
        yield 'missing message' => [[]];
        yield 'non-string message' => [['message' => 42]];
        yield 'chosen author' => [['message' => self::MESSAGE, 'createdBy' => '0190f1b2-0000-7000-8000-000000000000']];
        yield 'chosen author name' => [['message' => self::MESSAGE, 'authorName' => 'Someone else']];
        yield 'chosen uuid' => [['message' => self::MESSAGE, 'uuid' => '0190f1b2-0000-7000-8000-000000000000']];
        yield 'chosen country' => [['message' => self::MESSAGE, 'countryCode' => 'FR']];
        yield 'chosen type' => [['message' => self::MESSAGE, 'type' => 'human']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('malformedArguments')]
    public function testArgumentsOutsideTheSchemaAreRejected(array $arguments): void
    {
        $bot = $this->participant(ParticipantType::Bot);
        $before = $this->countPlanes();

        $data = $this->launch('Bearer '.$bot->getCode(), $arguments);

        self::assertSame(-32602, $data['error']['code']);
        self::assertSame($before, $this->countPlanes());
    }

    /**
     * Replaces the real agent, so no test calls DeepSeek. Each call consumes one outcome (a generated response, or an
     * exception to throw); the last one repeats.
     *
     * @param array{text: string, tone: string}|\Throwable ...$outcomes
     */
    private function stubAgent(array|\Throwable ...$outcomes): void
    {
        $agent = $this->createStub(AgentInterface::class);
        $agent->method('call')->willReturnCallback(function () use (&$outcomes): ExecutionInterface {
            $outcome = 1 < \count($outcomes) ? array_shift($outcomes) : $outcomes[0];
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }
            $execution = $this->createStub(ExecutionInterface::class);
            $execution->method('getContent')->willReturn($outcome);

            return $execution;
        });

        $container = static::getContainer();
        $container->set(PaperPlaneAiManager::class, new PaperPlaneAiManager(
            $container->get(PaperPlaneManager::class),
            $agent,
            $container->get(ValidatorInterface::class),
            $this->em,
            new NullLogger(),
            $container->get(LockFactory::class),
            true,
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, string $authorization, array $arguments): array
    {
        return $this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $authorization, ['HTTP_MCP_NAME' => $name]);
    }

    private function countPlanes(): int
    {
        return static::getContainer()->get(PaperPlaneRepository::class)->count([]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function launch(?string $authorization, array $arguments): array
    {
        return $this->mcp('tools/call', ['name' => 'launch_paper_plane', 'arguments' => (object) $arguments], $authorization, ['HTTP_MCP_NAME' => 'launch_paper_plane']);
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
