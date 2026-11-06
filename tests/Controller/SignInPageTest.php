<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** "Sign in with your code": what /recover serves before any script runs. */
class SignInPageTest extends WebTestCase
{
    private function page(string $uri = '/recover'): Crawler
    {
        $crawler = static::createClient()->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testInputKeepsTheCodeOutOfTheBrowser(): void
    {
        $input = $this->page()->filter('input#code');

        self::assertSame('off', $input->attr('autocomplete'));
        self::assertSame('characters', $input->attr('autocapitalize'));
        self::assertSame('false', $input->attr('spellcheck'));
        self::assertSame('19', $input->attr('maxlength'));
        self::assertSame('XXXX-XXXX-XXXX-XXXX', $input->attr('placeholder'));
    }

    public function testFormFollowsTheContract(): void
    {
        $crawler = $this->page();

        self::assertSame('Sign in with your code', $crawler->filter('h1')->text());
        self::assertSame('RECOVERY CODE', $crawler->filter('label[for="code"]')->text());
        self::assertSame('alert', $crawler->filter('#code-error')->attr('role'));
        self::assertSame('Sign in', $crawler->filter('#submit')->text());
        self::assertNotNull($crawler->filter('#submit')->attr('disabled'));
        self::assertStringContainsString('never shown in links or stored in your browser', $crawler->filter('form')->text());
    }

    public function testLinksLeadToRegistration(): void
    {
        $crawler = $this->page();

        self::assertSame('Sign up', $crawler->filter('nav[aria-label="Main"] a')->text());
        self::assertSame('/register', $crawler->filter('nav[aria-label="Main"] a')->attr('href'));
        self::assertSame('create a new identity', $crawler->filter('main p a[href="/register"]')->text());
    }

    public function testComingFromAPlaneKeepsTheWayBack(): void
    {
        $plane = '0195f3a0-7c11-7b3e-9f6a-2d4c8e1a5b90';
        $crawler = $this->page("/recover?plane=$plane");

        self::assertSame("/?plane=$plane", $crawler->filter('#recovery')->attr('data-continue-url'));
        self::assertSame("/register?plane=$plane", $crawler->filter('nav[aria-label="Main"] a')->attr('href'));
    }

    public function testAForeignReturnPlaneIsIgnored(): void
    {
        self::assertSame('/', $this->page('/recover?plane=https://evil.example')->filter('#recovery')->attr('data-continue-url'));
    }

    public function testTheOldLookIsGone(): void
    {
        $html = $this->page()->filter('main')->html();

        foreach (['amber-', 'from-white', 'sky-600', 'sky-700', 'rounded-3xl', '🔑'] as $old) {
            self::assertStringNotContainsString($old, $html);
        }
    }

    public function testThePageIsNeverCached(): void
    {
        $this->page();

        self::assertStringContainsString('no-store', (string) static::getClient()->getResponse()->headers->get('Cache-Control'));
    }
}
