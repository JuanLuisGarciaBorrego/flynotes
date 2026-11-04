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
use App\Manager\PlaneVisitManager;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/me/paper_planes/stats: totals over all the session participant's planes. */
class MyPaperPlaneStatsApiTest extends WebTestCase
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

    private function visits(): PlaneVisitManager
    {
        return static::getContainer()->get(PlaneVisitManager::class);
    }

    private function plane(Participant $author, string $message = 'Un avión de papel cualquiera'): PaperPlane
    {
        return static::getContainer()->get(PaperPlaneManager::class)->create($author, $message);
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $this->client->request('GET', '/api/me/paper_planes/stats', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testAnonymousIsRejected(): void
    {
        $this->stats();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testBotIsForbidden(): void
    {
        $this->client->loginUser($this->participants()->create(ParticipantType::Bot, 'Bot'), 'main');
        $this->stats();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testWithoutPlanesEverythingIsZero(): void
    {
        $this->client->loginUser($this->author, 'main');
        $data = $this->stats();

        self::assertResponseIsSuccessful();
        self::assertSame(['totalPlanes' => 0, 'totalViews' => 0, 'totalCountries' => 0], array_diff_key($data, array_flip(['@context', '@id', '@type'])));
    }

    public function testTotalsCoverAllMyPlanesAndOnlyMine(): void
    {
        $french = $this->participants()->create(ParticipantType::Human, 'Lectora', 'FR');
        $japanese = $this->participants()->create(ParticipantType::Human, 'Lector', 'JP');
        $stateless = $this->participants()->create(ParticipantType::Human, 'Sin país');

        // More planes than one page of GET /me/paper_planes: the totals are not per page.
        $mine = [];
        for ($i = 0; $i < 22; ++$i) {
            $mine[] = $this->plane($this->author);
        }
        // Views: every read counts, also a repeated one and an anonymous one (not unique readers).
        $this->visits()->registerView($mine[0], $french);
        $this->visits()->registerView($mine[0], $french);
        $this->visits()->registerView($mine[1], null);
        $this->visits()->registerView($mine[21], $japanese);
        // Countries: FR on two planes counts once; a stamp without country does not count.
        $this->visits()->registerParticipation($mine[0], $french);
        $this->visits()->registerParticipation($mine[21], $french);
        $this->visits()->registerParticipation($mine[21], $japanese);
        // PlaneVisitManager no longer lets a participant without country stamp; the query must still ignore
        // such a row (e.g. older data), so it is stored directly.
        $this->em->persist(new PlaneVisit()->setPlane($mine[2])->setParticipant($stateless)->setType(PlaneVisitType::Participation)->setCountryCode(null)->setEmoji(null)->setMessage(null));
        $this->em->flush();

        // Someone else's plane, read and stamped from another country: not part of my totals.
        $theirs = $this->plane($french);
        $this->visits()->registerView($theirs, $this->author);
        $this->visits()->registerParticipation($theirs, $this->author);

        $this->client->loginUser($this->author, 'main');
        $data = $this->stats();

        self::assertResponseIsSuccessful();
        self::assertSame('/api/me/paper_planes/stats', $data['@id']);
        self::assertSame(22, $data['totalPlanes']);
        self::assertSame(4, $data['totalViews']);
        self::assertSame(2, $data['totalCountries']);
        self::assertSame(['@context', '@id', '@type', 'totalPlanes', 'totalViews', 'totalCountries'], array_keys($data));

        $this->client->loginUser($french, 'main');
        $theirStats = $this->stats();
        self::assertSame([1, 1, 1], [$theirStats['totalPlanes'], $theirStats['totalViews'], $theirStats['totalCountries']]);
    }

    public function testOwnerCannotBeChosenByTheClient(): void
    {
        $other = $this->participants()->create(ParticipantType::Human, 'Otra', 'PT');
        $this->plane($other);

        $this->client->loginUser($this->author, 'main');
        $this->client->request('GET', '/api/me/paper_planes/stats?participant='.$other->getUuid()->toRfc4122().'&author='.$other->getId(), server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['totalPlanes']);
    }

    public function testIsOneQueryNeverCachedAndRegistersNoVisit(): void
    {
        $plane = $this->plane($this->author);
        $this->visits()->registerView($plane, $this->participants()->create(ParticipantType::Human, 'Lectora', 'FR'));
        $visits = static::getContainer()->get(PlaneVisitRepository::class);
        $before = $visits->count([]);

        $this->client->loginUser($this->author, 'main');
        static::getContainer()->get('doctrine.debug_data_holder')->reset(); // drop the seeding queries
        $this->client->enableProfiler();
        $data = $this->stats();

        $sql = array_column($this->client->getProfile()->getCollector('db')->getQueries()['default'], 'sql');
        self::assertCount(1, array_filter($sql, static fn (string $query): bool => str_contains($query, 'paper_plane')));
        self::assertSame(1, $data['totalViews']);
        self::assertSame($before, $visits->count([]));

        // Personal data: Symfony marks session-based responses private, so no shared cache keeps them.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('must-revalidate', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }
}
