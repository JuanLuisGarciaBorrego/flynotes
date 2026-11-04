<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\ApiResource\MyPaperPlane;
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
use Symfony\Component\HttpFoundation\Response;

/** GET /api/me/paper_planes: the session participant's own planes, paginated, newest first. */
class MyPaperPlaneApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $author;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the seeded transaction visible across requests
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->author = $this->participants()->create(ParticipantType::Human, 'Autora', 'ES');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function participants(): ParticipantManager
    {
        return static::getContainer()->get(ParticipantManager::class);
    }

    private function planes(): PaperPlaneManager
    {
        return static::getContainer()->get(PaperPlaneManager::class);
    }

    private function plane(Participant $author, string $message, string $createdAt): PaperPlane
    {
        $plane = $this->planes()->create($author, $message);
        $plane->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->em->flush();

        return $plane;
    }

    /** @return array<string, mixed> */
    private function get(string $query = '', string $accept = 'application/ld+json'): array
    {
        $this->client->request('GET', '/api/me/paper_planes'.$query, server: ['HTTP_ACCEPT' => $accept]);

        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testAnonymousIsRejected(): void
    {
        $this->get();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testBotIsForbidden(): void
    {
        $bot = $this->participants()->create(ParticipantType::Bot, 'Bot');
        $this->client->loginUser($bot, 'main');
        $this->get();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testReturnsOnlyMyPlanesNewestFirstWithCountsAndNothingPrivate(): void
    {
        $older = $this->plane($this->author, 'Mi primer avión de papel', '2026-01-01 10:00');
        $newer = $this->plane($this->author, 'Mi segundo avión de papel', '2026-11-02 18:10');

        $french = $this->participants()->create(ParticipantType::Human, 'Lectora', 'FR');
        $japanese = $this->participants()->create(ParticipantType::Human, 'Lector', 'JP');
        $theirs = $this->plane($french, 'Un avión que no es mío', '2026-11-02 19:00');
        $visits = static::getContainer()->get(PlaneVisitManager::class);
        $visits->registerView($newer, $french);
        $visits->registerView($newer, $japanese);
        $visits->registerView($older, $french);
        $visits->registerView($theirs, $this->author);
        $stamp = $visits->registerParticipation($newer, $french);
        $visits->registerParticipation($newer, $japanese);

        $this->client->loginUser($this->author, 'main');
        $data = $this->get();

        self::assertResponseIsSuccessful();
        // Personal data: Symfony marks session-based responses private, so no shared cache keeps them.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('must-revalidate', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertSame(2, $data['totalItems']);
        self::assertSame([$newer->getUuid()->toRfc4122(), $older->getUuid()->toRfc4122()], array_column($data['member'], 'uuid'));

        $first = $data['member'][0];
        // Each item points at the public detail, which is where a plane is read.
        self::assertSame('/api/paper_planes/'.$newer->getUuid()->toRfc4122(), $first['@id']);
        self::assertSame(['@id', '@type', 'uuid', 'message', 'createdAt', 'viewCount', 'stampCount', 'stamps'], array_keys($first));
        self::assertSame('Mi segundo avión de papel', $first['message']);
        self::assertSame(new \DateTimeImmutable('2026-11-02 18:10')->format(\DATE_ATOM), $first['createdAt']);
        self::assertSame(2, $first['viewCount']);
        self::assertSame(2, $first['stampCount']);
        self::assertSame(['FR', 'JP'], array_column($first['stamps'], 'countryCode'));
        self::assertSame([
            'uuid' => $stamp->getUuid()->toRfc4122(),
            'countryCode' => 'FR',
            'color' => static::getContainer()->get(StampColorHelper::class)->colorFor($stamp->getUuid()),
        ], $first['stamps'][0]);

        self::assertSame(1, $data['member'][1]['viewCount']);
        self::assertSame(0, $data['member'][1]['stampCount']);

        $body = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Un avión que no es mío', $body);
        foreach ([$this->author, $french, $japanese] as $participant) {
            self::assertStringNotContainsString($participant->getCode(), $body);
            self::assertStringNotContainsString($participant->getUuid()->toRfc4122(), $body);
        }
    }

    public function testAnotherParticipantGetsTheirOwnCollection(): void
    {
        $this->plane($this->author, 'Mi primer avión de papel', '2026-01-01 10:00');
        $other = $this->participants()->create(ParticipantType::Human, 'Otra', 'PT');
        $hers = $this->plane($other, 'El avión de otra persona', '2026-02-01 10:00');

        $this->client->loginUser($other, 'main');
        $data = $this->get();

        self::assertSame(1, $data['totalItems']);
        self::assertSame([$hers->getUuid()->toRfc4122()], array_column($data['member'], 'uuid'));
    }

    public function testOwnerCannotBeChosenByTheClient(): void
    {
        $other = $this->participants()->create(ParticipantType::Human, 'Otra', 'PT');
        $this->plane($other, 'El avión de otra persona', '2026-02-01 10:00');

        $this->client->loginUser($this->author, 'main');
        $query = '?createdBy='.$other->getUuid()->toRfc4122().'&participant='.$other->getUuid()->toRfc4122().'&author='.$other->getId();

        self::assertSame(0, $this->get($query)['totalItems']);
    }

    public function testIsPaginatedByTwentyInTheDatabase(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->plane($this->author, \sprintf('Avión de papel número %02d', $i), \sprintf('2026-01-%02d 10:00', $i));
        }
        $this->client->loginUser($this->author, 'main');

        $first = $this->get();
        self::assertSame(MyPaperPlane::ITEMS_PER_PAGE, 20);
        self::assertSame(25, $first['totalItems']);
        self::assertCount(20, $first['member']);
        self::assertSame('Avión de papel número 25', $first['member'][0]['message']);
        self::assertSame('/api/me/paper_planes?page=1', $first['view']['@id']);
        self::assertSame('/api/me/paper_planes?page=2', $first['view']['next']);
        self::assertSame('/api/me/paper_planes?page=2', $first['view']['last']);

        $second = $this->get('?page=2');
        self::assertCount(5, $second['member']);
        self::assertSame('Avión de papel número 05', $second['member'][0]['message']);
        self::assertSame('Avión de papel número 01', $second['member'][4]['message']);
        self::assertArrayNotHasKey('next', $second['view']);
        self::assertSame([], array_intersect(array_column($first['member'], 'uuid'), array_column($second['member'], 'uuid')));

        // The page size is fixed by the server.
        self::assertCount(20, $this->get('?itemsPerPage=100')['member']);
    }

    public function testQueryCountDoesNotGrowWithThePlanes(): void
    {
        $reader = $this->participants()->create(ParticipantType::Human, 'Lectora', 'FR');
        $prolific = $this->participants()->create(ParticipantType::Human, 'Prolífica', 'IT');
        $visits = static::getContainer()->get(PlaneVisitManager::class);
        foreach ([[$this->author, 2], [$prolific, 12]] as [$author, $count]) {
            for ($i = 1; $i <= $count; ++$i) {
                $plane = $this->plane($author, \sprintf('Avión de papel número %02d', $i), \sprintf('2026-01-%02d 10:00', $i));
                $visits->registerView($plane, $reader);
                $visits->registerParticipation($plane, $reader);
            }
        }
        $ids = [$this->author->getId(), $prolific->getId()];

        $queries = [];
        foreach ($ids as $id) {
            $this->em->clear(); // nothing preloaded: every plane and stamp comes from this request
            $this->client->loginUser($this->em->find(Participant::class, $id), 'main');
            static::getContainer()->get('doctrine.debug_data_holder')->reset(); // drop the seeding queries
            $this->client->enableProfiler();
            $data = $this->get();
            // Only the planes' queries: the session user may or may not be refreshed depending on the identity map.
            $sql = array_column($this->client->getProfile()->getCollector('db')->getQueries()['default'], 'sql');
            $queries[] = \count(array_filter($sql, static fn (string $query): bool => str_contains($query, 'paper_plane') || str_contains($query, 'plane_visit')));
        }

        self::assertSame(12, $data['totalItems']);
        // One page of planes with their views, the total count and the page's stamps: whatever the number of planes.
        self::assertSame([3, 3], $queries);
    }

    public function testListingDoesNotRegisterVisits(): void
    {
        $plane = $this->plane($this->author, 'Mi primer avión de papel', '2026-01-01 10:00');
        static::getContainer()->get(PlaneVisitManager::class)->registerView($plane, $this->participants()->create(ParticipantType::Human, 'Lectora', 'FR'));
        $visits = static::getContainer()->get(PlaneVisitRepository::class);
        $before = $visits->count([]);

        $this->client->loginUser($this->author, 'main');
        $this->get();
        $data = $this->get();

        self::assertSame(1, $data['member'][0]['viewCount']);
        self::assertSame($before, $visits->count([]));
    }

    public function testPlainJsonIsAListToo(): void
    {
        $this->plane($this->author, 'Mi primer avión de papel', '2026-01-01 10:00');
        $this->client->loginUser($this->author, 'main');

        $data = $this->get(accept: 'application/json');

        self::assertResponseIsSuccessful();
        self::assertSame(['Mi primer avión de papel'], array_column($data, 'message'));
    }
}
