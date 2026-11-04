<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Manager\PlaneVisitManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /my-planes: only the page shell. The planes and the totals come from GET /api/me/paper_planes and
 * GET /api/me/paper_planes/stats (see MyPaperPlaneApiTest and MyPaperPlaneStatsApiTest), loaded by my-planes.js.
 */
class MyPlanesControllerTest extends WebTestCase
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

        $this->author = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testAnonymousIsSentToRegistration(): void
    {
        $this->client->request('GET', '/my-planes');

        self::assertResponseRedirects('/register');
    }

    public function testRendersTheShellForTheScriptWithoutQueryingThePlanes(): void
    {
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($this->author, 'Un mensaje que no está en el HTML');
        $reader = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Lectora', 'FR');
        static::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $reader);

        $this->client->loginUser($this->author, 'main');
        static::getContainer()->get('doctrine.debug_data_holder')->reset(); // drop the seeding queries
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/my-planes');

        self::assertResponseIsSuccessful();
        $sql = array_column($this->client->getProfile()->getCollector('db')->getQueries()['default'] ?? [], 'sql');
        self::assertSame([], array_values(array_filter($sql, static fn (string $query): bool => str_contains($query, 'paper_plane') || str_contains($query, 'plane_visit'))));

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        // No plane data in the HTML: the list is empty and the totals are placeholders.
        self::assertStringNotContainsString('Un mensaje que no está en el HTML', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($plane->getUuid()->toRfc4122(), $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('#my-planes > li'));
        self::assertSame(['totalPlanes', 'totalViews', 'totalCountries'], $crawler->filter('#my-planes-summary dd')->each(static fn ($node) => $node->attr('data-stat')));
        self::assertSame(['—', '—', '—'], $crawler->filter('#my-planes-summary dd')->each(static fn ($node) => $node->text()));
        self::assertSame(
            ['planes launched', 'times read', 'countries that stamped'],
            $crawler->filter('#my-planes-summary dt')->each(static fn ($node) => $node->text()),
        );

        // Initial state: only the loader is visible; everything else waits for the API.
        self::assertSame('status', $crawler->filter('#my-planes-loading')->attr('role'));
        self::assertNull($crawler->filter('#my-planes-loading')->attr('hidden'));
        foreach (['#my-planes-error', '#my-planes-empty', '#my-planes-summary', '#my-planes', '#my-planes-pagination'] as $selector) {
            self::assertNotNull($crawler->filter($selector)->attr('hidden'), $selector);
        }
        self::assertSame('alert', $crawler->filter('#my-planes-error')->attr('role'));
        self::assertSelectorTextContains('#my-planes-empty', "You haven't launched any planes yet");

        // What the script needs: the card component, the URLs (from the router) and the shared modal.
        $page = $crawler->filter('#my-planes-page');
        self::assertSame('/', $page->attr('data-home-url'));
        self::assertSame('/register', $page->attr('data-registration-url'));
        $card = $crawler->filter('template#my-plane-card');
        self::assertCount(1, $card);
        foreach (['[data-status="flying"]', '[data-status="waiting"]', 'time', '[data-message]', '[data-views]', '[data-stamps]', '[data-mini-stamps]', '[data-no-stamps]', '[data-open-plane]'] as $part) {
            self::assertStringContainsString(trim($part, '[]'), $card->html(), $part);
        }
        self::assertCount(2, $crawler->filter('#my-planes-pagination button[data-page]'));
        self::assertCount(1, $crawler->filter('dialog#plane-dialog'));
    }
}
