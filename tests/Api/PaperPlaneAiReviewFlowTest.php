<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PaperPlane;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneAiManager;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The HTTP contract the review dialog relies on (assets/js/paper-plane.js), request by request as the browser sends
 * them, with the agent replaced so that DeepSeek is never called.
 */
class PaperPlaneAiReviewFlowTest extends WebTestCase
{
    private const string MESSAGE = 'Hoy ha sido un día complicado.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES'), 'main');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testOnlyTheLatestVersionCanBeApprovedAndEndsTheReview(): void
    {
        $this->stubAgent(['text' => 'Versión uno', 'tone' => 'Juguetón'], ['text' => 'Versión dos', 'tone' => 'Tierno'], ['text' => 'Versión tres', 'tone' => 'Irónico']);
        $first = $this->launch();

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('pending', $first['aiStatus']);
        self::assertSame('Versión uno', $first['aiMessage']);
        self::assertSame('Juguetón', $first['aiTone']);
        self::assertSame(1, $first['aiAttempts']);
        self::assertSame(PaperPlane::AI_MAX_ATTEMPTS - 1, $first['aiAttemptsRemaining']);

        $second = $this->post($first['uuid'], 'retry');
        self::assertSame('Versión dos', $second['aiMessage']);
        self::assertNotSame($first['aiVersionId'], $second['aiVersionId']);
        $third = $this->post($first['uuid'], 'retry');
        self::assertSame(0, $third['aiAttemptsRemaining']);

        // No generations left: only regenerating is refused, the author can still decide.
        $this->post($first['uuid'], 'retry');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->post($first['uuid'], 'decision', ['accepted' => true, 'versionId' => $first['aiVersionId']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $accepted = $this->post($first['uuid'], 'decision', ['accepted' => true, 'versionId' => $third['aiVersionId']]);
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $accepted['aiStatus']);
        self::assertSame('Versión tres', $accepted['aiMessage']);
        self::assertSame($third['aiVersionId'], $accepted['aiVersionId']);
        self::assertSame(self::MESSAGE, $accepted['message']);

        $this->post($first['uuid'], 'decision', ['accepted' => false, 'versionId' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->post($first['uuid'], 'retry');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testRejectingWithANullVersionKeepsOnlyTheOriginal(): void
    {
        $this->stubAgent(['text' => 'Versión uno', 'tone' => 'Juguetón']);
        $plane = $this->launch();

        $rejected = $this->post($plane['uuid'], 'decision', ['accepted' => false, 'versionId' => null]);

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $rejected['aiStatus']);
        self::assertSame(self::MESSAGE, $rejected['message']);
        self::assertNull($rejected['aiMessage'] ?? null);
        self::assertNull($rejected['aiVersionId'] ?? null);
    }

    /** A failed generation still creates the plane, costs no attempt and leaves only "ask again" available. */
    public function testAFailedGenerationLeavesThePlaneWithoutAProposalAndCanBeRetried(): void
    {
        $this->stubAgent(new \RuntimeException('DeepSeek is down'), new \RuntimeException('Still down'), ['text' => 'Por fin', 'tone' => 'Juguetón']);
        $plane = $this->launch();

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(self::MESSAGE, $plane['message']);
        self::assertNull($plane['aiStatus'] ?? null);
        self::assertNull($plane['aiVersionId'] ?? null);
        self::assertSame(0, $plane['aiAttempts']);

        $this->post($plane['uuid'], 'decision', ['accepted' => false, 'versionId' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->post($plane['uuid'], 'retry');
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);

        $retried = $this->post($plane['uuid'], 'retry');
        self::assertResponseIsSuccessful();
        self::assertSame('pending', $retried['aiStatus']);
        self::assertSame('Por fin', $retried['aiMessage']);
        self::assertSame(1, $retried['aiAttempts']);
    }

    /** What the dialog re-reads after a failed call: the author gets the counters and the latest pending version. */
    public function testTheAuthorCanReadBackTheLatestPendingVersion(): void
    {
        $this->stubAgent(['text' => 'Versión uno', 'tone' => 'Juguetón'], ['text' => 'Versión dos', 'tone' => 'Tierno']);
        $plane = $this->launch();
        $second = $this->post($plane['uuid'], 'retry');

        $this->client->request('GET', '/api/paper_planes/'.$plane['uuid'], server: ['HTTP_ACCEPT' => 'application/ld+json']);
        $read = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertSame($second['aiVersionId'], $read['aiVersionId']);
        self::assertSame('Versión dos', $read['aiMessage']);
        self::assertSame(2, $read['aiAttempts']);
    }

    /** @return array<string, mixed> */
    private function launch(): array
    {
        $this->client->request('POST', '/api/paper_planes', server: ParticipantApiTest::jsonLd(), content: json_encode(['message' => self::MESSAGE], \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed>|null $body null sends no body, as the retry does
     *
     * @return array<string, mixed>
     */
    private function post(string $uuid, string $action, ?array $body = null): array
    {
        $this->client->request(
            'POST',
            '/api/paper_planes/'.$uuid.'/ai-message/'.$action,
            server: ParticipantApiTest::jsonLd(),
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Each call consumes one outcome (a generated response, or an exception to throw); the last one repeats.
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
}
