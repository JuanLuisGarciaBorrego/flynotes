<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/** What base.html.twig tells search engines, social networks and analytics. */
class SeoMetadataTest extends WebTestCase
{
    private const string SITE = 'https://flynotes.vitaminatech.com';
    private const string HOME_TITLE = 'FlyNotes — Where Humans and AI Share the Sky';
    private const string HOME_DESCRIPTION = 'FlyNotes is an interactive playground where humans and AI agents exchange virtual paper planes. Powered by Symfony AI and MCP, with human-approved AI creativity.';
    private const string UMAMI = 'script[src="https://umami.vitaminatech.com/script.js"]';

    private static function meta(Crawler $page, string $selector): ?string
    {
        self::assertCount(1, $page->filter($selector), $selector.' must appear exactly once.');

        return $page->filter($selector)->attr('content');
    }

    /** The social cards repeat what the page declares, so a page's own title and description reach them too. */
    private static function assertSocialCardsMatch(Crawler $page, string $title, string $description, string $url): void
    {
        self::assertSame($title, $page->filter('title')->text());
        self::assertSame($description, self::meta($page, 'meta[name="description"]'));
        self::assertSame($title, self::meta($page, 'meta[property="og:title"]'));
        self::assertSame($description, self::meta($page, 'meta[property="og:description"]'));
        self::assertSame($title, self::meta($page, 'meta[name="twitter:title"]'));
        self::assertSame($description, self::meta($page, 'meta[name="twitter:description"]'));
        self::assertSame('FlyNotes', self::meta($page, 'meta[property="og:site_name"]'));
        self::assertSame($url, self::meta($page, 'meta[property="og:url"]'));
        self::assertCount(1, $page->filter('link[rel="canonical"]'));
        self::assertSame($url, $page->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testHomeDescribesFlyNotesWithTheProductionDomain(): void
    {
        $page = static::createClient()->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSocialCardsMatch($page, self::HOME_TITLE, self::HOME_DESCRIPTION, self::SITE.'/');
    }

    public function testAPlaneOpenedOverTheHomeIsStillTheHome(): void
    {
        $page = static::createClient()->request('GET', '/?plane=00000000-0000-4000-8000-000000000000');

        self::assertSame(self::SITE.'/', $page->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testPagesKeepTheirOwnTitleAndDescription(): void
    {
        $client = static::createClient();

        self::assertSocialCardsMatch(
            $client->request('GET', '/sky-pulse'),
            'Sky Pulse · FlyNotes',
            'Sky Pulse is the research agent of FlyNotes: the AI investigates and proposes, but humans decide what runs and what gets published.',
            self::SITE.'/sky-pulse',
        );
        self::assertSocialCardsMatch(
            $client->request('GET', '/agents'),
            'Connect your AI agent · FlyNotes',
            'Humans and AI agents share the same sky. Connect your agent to the FlyNotes MCP server, give it an identity, and let it discover stories through paper planes.',
            self::SITE.'/agents',
        );
        // Without a description of its own, a page gets the general one.
        self::assertSocialCardsMatch(
            $client->request('GET', '/register'),
            'Create your identity · FlyNotes',
            self::HOME_DESCRIPTION,
            self::SITE.'/register',
        );
    }

    public function testNoAnalyticsOutsideProduction(): void
    {
        $client = static::createClient();

        foreach (['/', '/sky-pulse', '/agents', '/register'] as $path) {
            self::assertCount(0, $client->request('GET', $path)->filter(self::UMAMI), $path);
        }
    }

    public function testProductionLoadsAnalyticsOnceFromTheSharedLayout(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/'));
        $app = new AppVariable();
        $app->setEnvironment('prod');
        $app->setDebug(false);
        $app->setRequestStack($requests);

        self::bootKernel();
        $page = new Crawler(static::getContainer()->get(Environment::class)->render('base.html.twig', ['app' => $app]));

        $script = $page->filter(self::UMAMI);
        self::assertCount(1, $script);
        self::assertCount(1, $page->filter('head '.self::UMAMI));
        self::assertSame($_ENV['UMAMI_WEBSITE_ID'], $script->attr('data-website-id'));
        self::assertNotNull($script->attr('defer'));
    }
}
