<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/paper_planes/{uuid} records a visit via PaperPlaneManager::show(); the collection never does. */
class PlaneVisitApiTest extends WebTestCase
{
    private const string UNKNOWN_UUID = '0198f1a2-7b3c-7d4e-8f50-123456789abc';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $author;
    private Participant $reader;
    private PaperPlane $plane;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the seeded transaction visible across requests
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $participants = static::getContainer()->get(ParticipantManager::class);
        $this->author = $participants->create(ParticipantType::Human, 'Autor');
        $this->reader = $participants->create(ParticipantType::Human, 'Lector');
        $this->plane = static::getContainer()->get(PaperPlaneManager::class)->create($this->author, 'Hola');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function get(?Participant $as, ?string $uuid = null, array $headers = [], string $path = '/api/paper_planes/', string $method = 'GET'): void
    {
        $server = ['HTTP_ACCEPT' => 'application/ld+json'] + $headers;
        if (null !== $as) {
            $this->client->loginUser($as, 'main');
        } else {
            $this->client->getCookieJar()->clear();
        }
        $this->client->request($method, $path.($uuid ?? $this->plane->getUuid()->toRfc4122()), server: $server);
    }

    /** @return list<PlaneVisit> */
    private function visits(): array
    {
        $this->em->clear();

        return $this->em->getRepository(PlaneVisit::class)->findAll();
    }

    public function testDetailRecordsAViewForAuthenticatedHuman(): void
    {
        $this->get($this->reader);

        self::assertResponseIsSuccessful();
        $visits = $this->visits();
        self::assertCount(1, $visits);
        self::assertSame(PlaneVisitType::View, $visits[0]->getType());
        self::assertSame($this->plane->getId(), $visits[0]->getPlane()->getId());
        self::assertSame($this->reader->getId(), $visits[0]->getParticipant()->getId());
    }

    public function testRepeatedReadsAreAllCounted(): void
    {
        $this->get($this->reader);
        $this->get($this->reader);

        self::assertCount(2, $this->visits());
    }

    public function testAnonymousReadRecordsVisitWithoutParticipant(): void
    {
        $this->get(null);

        self::assertResponseIsSuccessful();
        $visits = $this->visits();
        self::assertCount(1, $visits);
        self::assertNull($visits[0]->getParticipant());
    }

    public function testAuthorReadingOwnPlaneIsNotRecorded(): void
    {
        $this->get($this->author);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->visits());
    }

    public function testUnknownUuidReturns404AndRecordsNothing(): void
    {
        $this->get($this->reader, self::UNKNOWN_UUID);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertCount(0, $this->visits());
    }

    public function testMalformedUuidReturns404(): void
    {
        $this->get($this->reader, 'not-a-uuid');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCollectionRecordsNothing(): void
    {
        $this->get($this->reader, '', path: '/api/paper_planes');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->visits());
    }

    public function testHeadRecordsNothing(): void
    {
        $this->get($this->reader, method: 'HEAD');

        self::assertCount(0, $this->visits());
    }

    /** @return iterable<string, array{string, string}> */
    public static function prefetchHeaders(): iterable
    {
        yield 'Sec-Purpose prefetch' => ['HTTP_SEC_PURPOSE', 'prefetch'];
        yield 'Sec-Purpose prefetch;prerender' => ['HTTP_SEC_PURPOSE', 'prefetch;prerender'];
        yield 'Purpose prefetch' => ['HTTP_PURPOSE', 'prefetch'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prefetchHeaders')]
    public function testPrefetchRecordsNothing(string $header, string $value): void
    {
        $this->get($this->reader, headers: [$header => $value]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->visits());
    }

    public function testJsonLdResourceIsStillReturned(): void
    {
        $this->get($this->reader);

        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->plane->getUuid()->toRfc4122(), $data['uuid']);
        self::assertSame('Hola', $data['message']);
        self::assertSame('Autor', $data['authorName']);
    }
}
