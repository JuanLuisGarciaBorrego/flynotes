<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The public /sky-pulse page shell. The states are painted by assets/js/sky-pulse.js from the API. */
class SkyPulseControllerTest extends WebTestCase
{
    private const array DECISION_BUTTONS = [
        ['tool', 'false', 'Reject'],
        ['tool', 'true', 'Approve research'],
        ['publication', 'false', 'Reject'],
        ['publication', 'true', 'Publish plane'],
    ];

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
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testAnonymousVisitorCanLookButHasNoButtonToStartOrDecide(): void
    {
        $crawler = $this->client->request('GET', '/sky-pulse');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sky Pulse');
        self::assertSelectorTextContains('main header', 'AI researches, humans decide.');
        self::assertCount(0, $crawler->filter('#sp-start'));
        self::assertCount(0, $crawler->filter('[data-decision]'));
        // Both decisions and the start invite to sign up instead.
        $signUp = static::getContainer()->get('router')->generate('app_registration');
        self::assertCount(3, $crawler->filter('#sp-intro a, #sp-tool-decision a, #sp-publish-decision a')->reduce(static fn ($link): bool => $signUp === $link->attr('href')));
    }

    public function testRegisteredHumanGetsTheButtons(): void
    {
        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Humana', 'ES'), 'main');

        $crawler = $this->client->request('GET', '/sky-pulse');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#sp-start', 'Start mission');
        self::assertCount(0, $crawler->filter('#sp-anonymous'));
        self::assertSame(self::DECISION_BUTTONS, $crawler->filter('[data-decision]')->each(static fn ($button): array => [$button->attr('data-decision'), $button->attr('data-accepted'), $button->text()]));
    }

    /** The session decides what this page shows: going back after signing up must not bring a stored copy. */
    public function testPageIsNeverStored(): void
    {
        $this->client->request('GET', '/sky-pulse');

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertTrue($headers->hasCacheControlDirective('private'));
    }

    public function testShellShipsNoMissionState(): void
    {
        $crawler = $this->client->request('GET', '/sky-pulse');

        foreach (['#sp-intro', '#sp-mission', '#sp-notice'] as $hiddenUntilThereIsData) {
            self::assertNotNull($crawler->filter($hiddenUntilThereIsData)->attr('hidden'), $hiddenUntilThereIsData);
        }
        self::assertSame('', $crawler->filter('#sp-timeline')->text());
        self::assertSame('', $crawler->filter('#sp-proposal-message')->text());
    }

    /** Six steps, each one in the name of who does it: the current one is marked by the script. */
    public function testProgressSaysWhoDoesEachStep(): void
    {
        $crawler = $this->client->request('GET', '/sky-pulse');

        self::assertSame(
            ['AI chooses', 'HUMAN approves', 'AI researches', 'AI proposes', 'HUMAN publishes', 'Plane in the sky'],
            $crawler->filter('#sp-flight li')->each(static fn ($step): string => $step->text()),
        );
        self::assertSame(
            ['ai', 'human', 'ai', 'ai', 'human', 'plane'],
            $crawler->filter('#sp-flight li')->each(static fn ($step): ?string => $step->attr('data-actor')),
        );
    }

    /** The decision is taken inside the card it is about, and the status can be heard as it changes. */
    public function testDecisionsLiveInsideTheProposal(): void
    {
        $crawler = $this->client->request('GET', '/sky-pulse');

        self::assertCount(2, $crawler->filter('article .pulse-decision'));
        self::assertCount(1, $crawler->filter('article #sp-outcome'));
        self::assertCount(1, $crawler->filter('article details#sp-evidence'));
        self::assertSame('status', $crawler->filter('#sp-status')->attr('role'));
        self::assertSame('status', $crawler->filter('#sp-outcome')->attr('role'));
    }

    /** The decision strip sticks on a phone, which overflow:hidden on the shell would prevent. Only this page changes. */
    public function testOnlyThisPageLetsItsContentStick(): void
    {
        $shell = static fn ($crawler): string => (string) $crawler->filter('body > div')->attr('class');

        self::assertStringContainsString('overflow-clip', $shell($this->client->request('GET', '/sky-pulse')));
        self::assertStringContainsString('overflow-hidden', $shell($this->client->request('GET', '/')));
    }

    public function testHomeLeadsToSkyPulse(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame('/sky-pulse', $crawler->filter('#sky-pulse-link')->attr('href'));
        self::assertStringStartsWith('Explore Sky Pulse', $crawler->filter('#sky-pulse-link')->text());
        // Where the violet plane of the sky goes (assets/js/home.js).
        self::assertSame('/sky-pulse', $crawler->filter('#planes')->attr('data-sky-pulse-url'));
    }

    /** Three plane colours, three meanings: the legend names them and the violet plane keeps being the way in. */
    public function testHomeExplainsThePlaneColours(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame(
            ['Human & Bot Messages', 'Sky Agent', 'Agent Reports'],
            $crawler->filter('#plane-legend [data-legend-label]')->each(static fn ($label): string => $label->text()),
        );
        self::assertSame(
            ['#FFFFFF', '#7C3AED', '#FACC15'],
            $crawler->filter('#plane-legend li svg path:first-child')->each(static fn ($wing): ?string => $wing->attr('fill')),
        );
    }

    /** The plane modal starts with the robot off: assets/js/plane-modal.js only turns it on for a report. */
    public function testPlaneModalShipsTheRobotHiddenNextToTheAuthorEmoji(): void
    {
        $avatar = $this->client->request('GET', '/')->filter('#plane-dialog #plane-author-avatar');

        self::assertCount(1, $avatar->filter('#plane-author-emoji'));
        self::assertStringContainsString('hidden', (string) $avatar->filter('#plane-author-robot')->attr('class'));
        self::assertSame('true', $avatar->attr('aria-hidden'), 'The author name next to it already says who it is.');
    }

    /** The agent's text and the tool results are untrusted: nothing may turn them into HTML. */
    public function testScriptNeverBuildsHtmlFromStrings(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/js/sky-pulse.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'localStorage', 'sessionStorage'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script);
        }
    }
}
