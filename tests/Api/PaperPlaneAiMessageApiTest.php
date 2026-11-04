<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Human-in-the-loop AI reinterpretation over HTTP: POST /api/paper_planes attaches a pending proposal, its author
 * retries (/ai-message/retry) or decides (/ai-message/decision); public reads only show accepted ones. With the real
 * `paper_plane` agent: only the model's answer is scripted.
 */
class PaperPlaneAiMessageApiTest extends WebTestCase
{
    private const string MESSAGE = 'Hoy ha sido un día complicado.';
    private const string UNKNOWN_UUID = '0198f1a2-7b3c-7d4e-8f50-123456789abc';
    private const string PROPOSAL = 'Hoy el día se puso complicado, y aun así aquí sigues.';
    private const string TONE = 'Tierno';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $author;
    private int $modelCalls = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): string {
            ++$this->modelCalls;

            return json_encode(['text' => self::PROPOSAL, 'tone' => self::TONE], \JSON_THROW_ON_ERROR);
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));

        $this->author = $this->participant('Autora', 'ES');
        $this->client->loginUser($this->author, 'main');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testCreationReturnsThePendingProposalToItsAuthor(): void
    {
        $created = $this->launch();

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(self::MESSAGE, $created['message']);
        self::assertSame(self::PROPOSAL, $created['aiMessage']);
        self::assertSame(self::TONE, $created['aiTone']);
        self::assertTrue(Uuid::isValid((string) $created['aiVersionId']));
        self::assertSame('pending', $created['aiStatus']);
        self::assertSame(1, $created['aiAttempts']);
        self::assertSame(2, $created['aiAttemptsRemaining']);
        self::assertSame($this->author->getEmoji(), $created['authorEmoji']);
        self::assertArrayNotHasKey('versionId', $created);
        self::assertArrayNotHasKey('accepted', $created);
    }

    public function testTheAiFieldsCannotBeSentOnCreation(): void
    {
        $created = $this->post('/api/paper_planes', ['message' => self::MESSAGE, 'aiMessage' => 'Elegido por el cliente', 'aiStatus' => 'accepted', 'aiAttempts' => 0, 'aiAttemptsRemaining' => 99]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(self::PROPOSAL, $created['aiMessage']);
        self::assertSame('pending', $created['aiStatus']);
        self::assertSame(1, $created['aiAttempts']);
        self::assertSame(2, $created['aiAttemptsRemaining']);
    }

    public function testTheMessageIsStillRequired(): void
    {
        $this->post('/api/paper_planes', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTwoRetriesThenConflict(): void
    {
        $uuid = $this->launch()['uuid'];

        $first = $this->post($this->retryUrl($uuid));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertTrue(Uuid::isValid((string) $first['aiVersionId']));
        self::assertSame([2, 1, 'pending'], [$first['aiAttempts'], $first['aiAttemptsRemaining'], $first['aiStatus']]);
        self::assertSame(self::PROPOSAL, $first['aiMessage']);
        self::assertSame(self::MESSAGE, $first['message']);

        $second = $this->post($this->retryUrl($uuid));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([3, 0], [$second['aiAttempts'], $second['aiAttemptsRemaining']]);

        $this->post($this->retryUrl($uuid));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(3, $this->stored($uuid)->getAiAttempts());
        self::assertSame(3, $this->modelCalls, 'A refused retry spends no generation.');
    }

    public function testAcceptingKeepsTheProposalAndMakesItPublic(): void
    {
        $created = $this->launch();
        $uuid = $created['uuid'];

        $decided = $this->post($this->decisionUrl($uuid), ['accepted' => true, 'versionId' => $created['aiVersionId']]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('accepted', $decided['aiStatus']);
        self::assertSame(self::PROPOSAL, $decided['aiMessage']);
        self::assertSame($created['aiVersionId'], $decided['aiVersionId']);
        self::assertSame(self::MESSAGE, $decided['message']);

        $this->client->loginUser($this->participant('Lectora', 'FR'), 'main');
        $public = $this->read($uuid);
        self::assertSame(self::MESSAGE, $public['message']);
        self::assertSame(self::PROPOSAL, $public['aiMessage']);
        self::assertSame(self::TONE, $public['aiTone']);
        self::assertSame('accepted', $public['aiStatus']);
    }

    /** A newer version generated meanwhile (another tab) replaced the one shown: approving it is refused, not redirected. */
    public function testAcceptingAReplacedVersionIsAConflictAndTheLatestStaysApprovable(): void
    {
        $created = $this->launch();
        $shown = $created['aiVersionId'];
        $latest = $this->post($this->retryUrl($created['uuid']))['aiVersionId'];
        self::assertNotSame($shown, $latest);

        $this->post($this->decisionUrl($created['uuid']), ['accepted' => true, 'versionId' => $shown]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $decided = $this->post($this->decisionUrl($created['uuid']), ['accepted' => true, 'versionId' => $latest]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($latest, $decided['aiVersionId']);
        $stored = $this->stored($created['uuid']);
        self::assertSame(2, $stored->getApprovedAiVersion()?->getNumber());
        // Every generation is kept.
        self::assertCount(2, $stored->getAiVersions());
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function invalidDecisions(): iterable
    {
        yield 'accepted without versionId' => [['accepted' => true], Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'malformed versionId' => [['accepted' => true, 'versionId' => 'not-a-uuid'], Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'unknown versionId' => [['accepted' => true, 'versionId' => self::UNKNOWN_UUID], Response::HTTP_CONFLICT];
        yield 'no decision' => [['versionId' => self::UNKNOWN_UUID], Response::HTTP_UNPROCESSABLE_ENTITY];
    }

    /** @param array<string, mixed> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecisions')]
    public function testInvalidDecisionsChangeNothing(array $payload, int $status): void
    {
        $uuid = $this->launch()['uuid'];

        $this->post($this->decisionUrl($uuid), $payload);

        self::assertResponseStatusCodeSame($status);
        self::assertSame('pending', $this->stored($uuid)->getAiStatus()?->value);
    }

    public function testAVersionOfAnotherPlaneIsRefused(): void
    {
        $uuid = $this->launch()['uuid'];
        $foreign = $this->launch()['aiVersionId'];

        $this->post($this->decisionUrl($uuid), ['accepted' => true, 'versionId' => $foreign]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('pending', $this->stored($uuid)->getAiStatus()?->value);
    }

    public function testRejectingDiscardsTheProposalAndKeepsTheOriginal(): void
    {
        $uuid = $this->launch()['uuid'];

        $decided = $this->post($this->decisionUrl($uuid), ['accepted' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('rejected', $decided['aiStatus']);
        self::assertNull($decided['aiMessage']);
        self::assertNull($decided['aiVersionId']);
        self::assertSame(self::MESSAGE, $decided['message']);

        $this->client->getCookieJar()->clear();
        $public = $this->read($uuid);
        self::assertSame(self::MESSAGE, $public['message']);
        self::assertNull($public['aiMessage']);
        self::assertSame('rejected', $public['aiStatus']);
        self::assertNull($this->stored($uuid)->getAiMessage());
    }

    public function testDecisionsAreFinal(): void
    {
        foreach ([true, false] as $accepted) {
            $created = $this->launch();
            $uuid = $created['uuid'];
            $version = $created['aiVersionId'];
            $this->post($this->decisionUrl($uuid), ['accepted' => $accepted, 'versionId' => $version]);
            self::assertResponseStatusCodeSame(Response::HTTP_OK);

            $this->post($this->retryUrl($uuid));
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            $this->post($this->decisionUrl($uuid), ['accepted' => !$accepted, 'versionId' => $version]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            $this->post($this->decisionUrl($uuid), ['accepted' => $accepted, 'versionId' => $version]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

            $stored = $this->stored($uuid);
            self::assertSame($accepted ? 'accepted' : 'rejected', $stored->getAiStatus()?->value);
            self::assertSame(1, $stored->getAiAttempts());
        }
    }

    public function testOnlyTheAuthorCanRetryOrDecide(): void
    {
        $created = $this->launch();
        $uuid = $created['uuid'];
        $accept = ['accepted' => true, 'versionId' => $created['aiVersionId']];
        $this->client->loginUser($this->participant('Otra', 'FR'), 'main');

        $this->post($this->retryUrl($uuid));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->post($this->decisionUrl($uuid), $accept);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->getCookieJar()->clear();
        $this->post($this->retryUrl($uuid));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->post($this->decisionUrl($uuid), $accept);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $stored = $this->stored($uuid);
        self::assertSame('pending', $stored->getAiStatus()?->value);
        self::assertSame(1, $stored->getAiAttempts());
    }

    public function testUnknownPlaneAndInvalidDecision(): void
    {
        $this->post($this->retryUrl(self::UNKNOWN_UUID));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->post($this->decisionUrl(self::UNKNOWN_UUID), ['accepted' => true, 'versionId' => self::UNKNOWN_UUID]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $uuid = $this->launch()['uuid'];
        $this->post($this->decisionUrl($uuid), []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('pending', $this->stored($uuid)->getAiStatus()?->value);
    }

    public function testAPendingProposalIsOnlyVisibleToItsAuthor(): void
    {
        $uuid = $this->launch()['uuid'];

        self::assertSame(self::PROPOSAL, $this->read($uuid)['aiMessage']);

        $this->client->loginUser($this->participant('Lectora', 'FR'), 'main');
        $other = $this->read($uuid);
        self::assertNull($other['aiMessage']);
        self::assertNull($other['aiVersionId']);
        self::assertNull($other['aiTone']);
        self::assertSame('pending', $other['aiStatus']);

        $this->client->getCookieJar()->clear();
        self::assertNull($this->read($uuid)['aiMessage']);

        // The random feed only carries uuids.
        $this->client->request('GET', '/api/paper_planes', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertStringNotContainsString(self::PROPOSAL, (string) $this->client->getResponse()->getContent());
    }

    public function testPlanesWithoutAiKeepWorking(): void
    {
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($this->participant('Bot', 'PT', ParticipantType::Bot), 'Un avión sin reinterpretación');

        $read = $this->read($plane->getUuid()->toRfc4122());
        self::assertSame('Un avión sin reinterpretación', $read['message']);
        self::assertNull($read['aiMessage']);
        self::assertNull($read['aiStatus']);
        self::assertSame(0, $read['aiAttempts']);
        self::assertSame(PaperPlane::AI_MAX_ATTEMPTS, $read['aiAttemptsRemaining']);
    }

    /** @return array<string, mixed> */
    private function launch(): array
    {
        $created = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $created;
    }

    /**
     * @param array<string, mixed>|null $payload null = no body
     *
     * @return array<string, mixed>|null
     */
    private function post(string $url, ?array $payload = null): ?array
    {
        $this->client->request('POST', $url, server: ParticipantApiTest::jsonLd(), content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function read(string $uuid): array
    {
        $this->client->request('GET', '/api/paper_planes/'.$uuid, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function stored(string $uuid): PaperPlane
    {
        $this->em->clear();

        return $this->em->getRepository(PaperPlane::class)->findOneBy(['uuid' => $uuid]) ?? self::fail('Plane not found.');
    }

    private function retryUrl(string $uuid): string
    {
        return '/api/paper_planes/'.$uuid.'/ai-message/retry';
    }

    private function decisionUrl(string $uuid): string
    {
        return '/api/paper_planes/'.$uuid.'/ai-message/decision';
    }

    private function participant(string $name, string $countryCode, ParticipantType $type = ParticipantType::Human): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, $name, $countryCode);
    }
}
