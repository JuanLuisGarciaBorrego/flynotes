<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The home on a phone: the dock, the sky's top bar, and the stamp zone of the plane's modal. */
class HomeMobileDockTest extends WebTestCase
{
    public function testDockFoldsWithAnAccessibleToggle(): void
    {
        $crawler = static::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();

        $toggle = $crawler->filter('#dock button#dock-toggle');
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertSame('dock-more', $toggle->attr('aria-controls'));
        self::assertSame('More', $toggle->text());
        self::assertCount(1, $crawler->filter('#dock-more h1'), 'the page keeps its title even when folded');
    }

    public function testDockCarriesTheProjectLinksOfTheHiddenFooter(): void
    {
        $crawler = static::createClient()->request('GET', '/');

        self::assertSame(['GitHub', 'About', 'API', 'MCP'], $crawler->filter('#dock-more nav a')->each(
            static fn ($link): string => trim(str_replace('(opens in a new tab)', '', $link->text())),
        ));
        self::assertStringContainsString('max-sm:hidden', (string) $crawler->filter('footer')->attr('class'));
    }

    public function testVisitorCanSignInFromTheDock(): void
    {
        $crawler = static::createClient()->request('GET', '/');

        self::assertSame('I have a code', $crawler->filter('#dock a[href="/recover"]')->text());
    }

    public function testSkyBarHasNoTapHintAndAnIconButtonWithAName(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertStringNotContainsString('Tap a plane to read it', $crawler->filter('#sky-bar')->text());
        self::assertSame('Legend: tap a plane to read it', $crawler->filter('#plane-legend')->attr('aria-label'));
        self::assertSame('More planes', $crawler->filter('#reload')->text());
    }

    public function testModalCarriesTheFlagsAndAnIsolatedStampZone(): void
    {
        $crawler = static::createClient()->request('GET', '/');

        self::assertCount(1, $crawler->filter('#plane-stamps.stamp-zone'));
        self::assertNotEmpty(json_decode($crawler->filter('script#flag-urls')->text(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('max-sm:z-10', (string) $crawler->filter('#plane-panel')->attr('class'));
    }
}
