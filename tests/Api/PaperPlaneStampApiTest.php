<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Helper\StampColorHelper;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** POST /api/paper_planes/{uuid}/stamps and the passport returned by GET /api/paper_planes/{uuid}. */
class PaperPlaneStampApiTest extends WebTestCase
{
    private const string UNKNOWN_UUID = '0198f1a2-7b3c-7d4e-8f50-123456789abc';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Participant $author;
    private Participant $french;
    private Participant $japanese;
    private PaperPlane $plane;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the seeded transaction visible across requests
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $participants = static::getContainer()->get(ParticipantManager::class);
        $this->author = $participants->create(ParticipantType::Human, 'Autor', 'ES');
        $this->french = $participants->create(ParticipantType::Human, 'Lectora', 'FR');
        $this->japanese = $participants->create(ParticipantType::Human, 'Lector', 'JP');
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

    private function as(?Participant $participant): void
    {
        if (null === $participant) {
            $this->client->getCookieJar()->clear();

            return;
        }
        $this->client->loginUser($participant, 'main');
    }

    /** @param array<string, string> $extra */
    private function stamp(?Participant $as, ?string $uuid = null, bool $csrf = true, string $content = '', array $extra = []): void
    {
        $this->as($as);
        $server = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'] + $extra;
        if ($csrf) {
            $server['HTTP_CSRF_TOKEN'] = bin2hex(random_bytes(16));
        }
        $this->client->request('POST', sprintf('/api/paper_planes/%s/stamps', $uuid ?? $this->plane->getUuid()->toRfc4122()), server: $server, content: $content);
    }

    /** @return array<string, mixed> */
    private function detail(?Participant $as): array
    {
        $this->as($as);
        $this->client->request('GET', '/api/paper_planes/'.$this->plane->getUuid()->toRfc4122(), server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<PlaneVisit> */
    private function participations(): array
    {
        $this->em->clear();

        return $this->em->getRepository(PlaneVisit::class)->findBy(['type' => PlaneVisitType::Participation]);
    }

    public function testHumanCanStampAndGetsCountryAndEmojiFromTheirIdentity(): void
    {
        $this->stamp($this->french);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('FR', $data['countryCode']);
        self::assertSame('France', $data['countryName']);
        self::assertSame($this->french->getEmoji(), $data['emoji']);

        $stamps = $this->participations();
        self::assertCount(1, $stamps);
        self::assertSame(PlaneVisitType::Participation, $stamps[0]->getType());
        self::assertSame('FR', $stamps[0]->getCountryCode());
        self::assertSame($this->french->getEmoji(), $stamps[0]->getEmoji());
        self::assertNull($stamps[0]->getMessage());
        self::assertSame($this->french->getId(), $stamps[0]->getParticipant()->getId());
        self::assertSame($this->plane->getId(), $stamps[0]->getPlane()->getId());
    }

    public function testClientCannotChooseCountryEmojiOrParticipant(): void
    {
        $body = json_encode(['countryCode' => 'JP', 'emoji' => '💥', 'participantId' => $this->japanese->getId(), 'type' => 'view', 'message' => 'x'], JSON_THROW_ON_ERROR);
        $this->stamp($this->french, content: $body);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $stamp = $this->participations()[0];
        self::assertSame('FR', $stamp->getCountryCode());
        self::assertSame($this->french->getEmoji(), $stamp->getEmoji());
        self::assertSame($this->french->getId(), $stamp->getParticipant()->getId());
        self::assertSame(PlaneVisitType::Participation, $stamp->getType());
        self::assertNull($stamp->getMessage());
    }

    public function testAnonymousCannotStamp(): void
    {
        $this->stamp(null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertCount(0, $this->participations());
    }

    public function testAuthorCannotStampOwnPlane(): void
    {
        $this->stamp($this->author);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(0, $this->participations());
    }

    public function testBotCannotStamp(): void
    {
        $bot = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot', 'US');
        $this->stamp($bot);

        self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
        self::assertCount(0, $this->participations());
    }

    /** Bots stamp only through MCP: their Bearer code is not a credential of the web (`main`) firewall. */
    public function testBotMcpCredentialIsNotAcceptedOnTheWebEndpoint(): void
    {
        $bot = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot', 'US');
        $this->stamp(null, extra: ['HTTP_AUTHORIZATION' => 'Bearer '.$bot->getCode()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertCount(0, $this->participations());
    }

    public function testHumanWithoutCountryCannotStamp(): void
    {
        $stateless = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Sin país');
        $this->stamp($stateless);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(0, $this->participations());
    }

    public function testSecondStampOnTheSamePlaneIsAConflict(): void
    {
        $this->stamp($this->french);
        $this->stamp($this->french);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertCount(1, $this->participations());
    }

    public function testUnknownAndMalformedPlanesReturn404(): void
    {
        $this->stamp($this->french, self::UNKNOWN_UUID);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->stamp($this->french, 'not-a-uuid');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertCount(0, $this->participations());
    }

    public function testCsrfProtectsTheStampWrite(): void
    {
        $this->stamp($this->french, csrf: false);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(0, $this->participations());
    }

    public function testStampingDoesNotStopViewsFromBeingRecorded(): void
    {
        $this->stamp($this->french);
        $this->detail($this->french);
        $this->detail($this->french);

        $this->em->clear();
        $repo = $this->em->getRepository(PlaneVisit::class);
        self::assertCount(2, $repo->findBy(['type' => PlaneVisitType::View]));
        self::assertCount(1, $repo->findBy(['type' => PlaneVisitType::Participation]));
    }

    public function testDetailReturnsThePassportOldestFirstWithoutPrivateData(): void
    {
        $this->stamp($this->french);
        $this->stamp($this->japanese);

        $data = $this->detail(null);

        self::assertSame(['FR', 'JP'], array_column($data['stamps'], 'countryCode'));
        self::assertSame([$this->french->getEmoji(), $this->japanese->getEmoji()], array_column($data['stamps'], 'emoji'));
        self::assertSame(['color', 'countryCode', 'countryName', 'createdAt', 'emoji', 'mine', 'uuid'], $this->sortedKeys($data['stamps'][0]));

        $raw = $this->client->getResponse()->getContent();
        foreach ([$this->french, $this->japanese, $this->author] as $participant) {
            self::assertStringNotContainsString($participant->getCode(), $raw);
            self::assertStringNotContainsString($participant->getUuid()->toRfc4122(), $raw);
        }
        self::assertStringNotContainsString('Lectora', $raw);
    }

    /** @param array<string, mixed> $array @return list<string> */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }

    public function testViewerFlagsForEachKindOfVisitor(): void
    {
        // Anonymous: reads, never stamps.
        $anonymous = $this->detail(null);
        self::assertFalse($anonymous['isAuthenticated']);
        self::assertFalse($anonymous['canStamp']);
        self::assertFalse($anonymous['hasStamped']);
        self::assertNull($anonymous['viewerCountryCode']);

        // Human who can stamp.
        $human = $this->detail($this->french);
        self::assertTrue($human['isAuthenticated']);
        self::assertTrue($human['canStamp']);
        self::assertFalse($human['hasStamped']);
        self::assertFalse($human['isAuthor']);
        self::assertSame('FR', $human['viewerCountryCode']);
        self::assertSame($this->french->getEmoji(), $human['viewerEmoji']);

        // After stamping.
        $this->stamp($this->french);
        $stamped = $this->detail($this->french);
        self::assertTrue($stamped['hasStamped']);
        self::assertFalse($stamped['canStamp']);

        // Author.
        $author = $this->detail($this->author);
        self::assertTrue($author['isAuthor']);
        self::assertFalse($author['canStamp']);
        self::assertFalse($author['hasStamped']);
        self::assertSame([], array_filter($author['stamps'], static fn (array $s): bool => 'ES' === $s['countryCode']));
    }

    public function testDetailIncludesAuthorCountryCreatedAtAndStampColors(): void
    {
        $this->stamp($this->french);
        $this->stamp($this->japanese);

        $data = $this->detail(null);

        self::assertSame('ES', $data['authorCountryCode']);
        self::assertNotNull(new \DateTimeImmutable($data['createdAt']));
        self::assertNull($data['stampedByMe'], 'No session: the flag is not computed.');
        self::assertCount(2, $data['stamps']);
        foreach ($data['stamps'] as $stamp) {
            self::assertContains($stamp['color'], StampColorHelper::PALETTE);
        }
        // The colour is stable: the same on every read.
        self::assertSame(array_column($data['stamps'], 'color'), array_column($this->detail($this->french)['stamps'], 'color'));
    }

    public function testDetailCountsViewsExceptTheAuthors(): void
    {
        self::assertSame(1, $this->detail(null)['viewCount']);
        self::assertSame(2, $this->detail($this->french)['viewCount']);
        // The author's own reads are not visits.
        self::assertSame(2, $this->detail($this->author)['viewCount']);
        // Stamping is not a view.
        $this->stamp($this->japanese);
        self::assertSame(3, $this->detail($this->french)['viewCount']);
    }

    public function testStampedByMeAndMineFollowTheSession(): void
    {
        self::assertFalse($this->detail($this->french)['stampedByMe']);

        $this->stamp($this->french);
        $created = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains($created['color'], StampColorHelper::PALETTE);

        $mine = $this->detail($this->french);
        self::assertTrue($mine['stampedByMe']);
        self::assertSame([true], array_column($mine['stamps'], 'mine'));
        self::assertSame($created['stampUuid'], $mine['stamps'][0]['uuid']);
        self::assertSame($created['color'], $mine['stamps'][0]['color']);

        $other = $this->detail($this->japanese);
        self::assertFalse($other['stampedByMe']);
        self::assertSame([false], array_column($other['stamps'], 'mine'));
    }

    public function testHomeTemplateHasTheThreePassportStates(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        foreach (['anonymous', 'can-stamp', 'stamped'] as $state) {
            self::assertStringContainsString(sprintf('data-stamp-state="%s"', $state), $html);
        }
        self::assertStringContainsString('Add my stamp', $html);
        self::assertStringNotContainsString('data-stamp-state="author"', $html, 'The own plane shows no side column.');
        self::assertStringContainsString('<dialog id="plane-dialog" aria-labelledby="plane-message"', $html);
        self::assertStringContainsString('aria-label="Close and go back to the sky"', $html);
        self::assertStringContainsString('This plane has no address', $html);
        self::assertStringNotContainsString('href="/planes/0', $html, 'A plane has no page of its own.');
    }

    public function testReturnPlaneParameterOnlyAcceptsUuids(): void
    {
        $uuid = $this->plane->getUuid()->toRfc4122();

        foreach (['/recover', '/register'] as $path) {
            $this->client->request('GET', $path.'?plane='.$uuid);
            self::assertStringContainsString('/?plane='.$uuid, $this->client->getResponse()->getContent());

            foreach (['https://evil.example/', '//evil.example', '/planes/new', 'javascript:alert(1)'] as $bad) {
                $this->client->request('GET', $path.'?plane='.urlencode($bad));
                self::assertResponseIsSuccessful();
                self::assertStringNotContainsString('evil.example', $this->client->getResponse()->getContent());
                self::assertStringNotContainsString('?plane=', $this->client->getResponse()->getContent());
            }
        }
    }
}
