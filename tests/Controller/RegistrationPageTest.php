<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Intl\Countries;

/** "Create your identity": what /register serves before any script runs. */
class RegistrationPageTest extends WebTestCase
{
    private function page(string $uri = '/register'): Crawler
    {
        $crawler = static::createClient()->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testFormFollowsTheContract(): void
    {
        $crawler = $this->page();

        self::assertSame('Create your identity', $crawler->filter('h1')->text());

        $name = $crawler->filter('input#name');
        self::assertSame('30', $name->attr('maxlength'));
        self::assertSame('e.g. Wind traveller', $name->attr('placeholder'));
        self::assertCount(1, $crawler->filter('label[for="name"]'));
        self::assertCount(1, $crawler->filter('label[for="countryCode"]'));

        $firstOption = $crawler->filter('select#countryCode option')->first();
        self::assertSame('', $firstOption->attr('value'));
        self::assertSame('Select your country', $firstOption->text());

        // Nothing can be sent until the client-side check has passed.
        self::assertSame('Create my identity', $crawler->filter('#submit')->text());
        self::assertNotNull($crawler->filter('#submit')->attr('disabled'));
    }

    public function testApiErrorsHaveAPlaceUnderEachField(): void
    {
        $crawler = $this->page();

        foreach (['name', 'countryCode'] as $field) {
            self::assertSame('alert', $crawler->filter("#$field-error")->attr('role'));
            self::assertStringContainsString("$field-error", (string) $crawler->filter("#$field")->attr('aria-describedby'));
        }
    }

    public function testPreviewStartsEmpty(): void
    {
        $crawler = $this->page();

        self::assertSame('Your nickname', $crawler->filter('#preview-name')->text());
        self::assertSame('Your country', $crawler->filter('#preview-country')->text());
        self::assertCount(1, $crawler->filter('#preview-stamp.-rotate-6'));
    }

    public function testEveryCountryHasAnSvgFlag(): void
    {
        $crawler = $this->page();

        $flags = json_decode($crawler->filter('script#flag-urls')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(Countries::getCountryCodes(), array_keys($flags));
        foreach ($flags as $code => $url) {
            self::assertMatchesRegularExpression('#^/assets/images/flags/'.strtolower($code).'-[^/]+\.svg$#', $url);
        }

        $offered = $crawler->filter('select#countryCode option[value!=""]')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertSame([], array_diff($offered, array_keys($flags)));
    }

    public function testHeaderAndLinksLeadToSignIn(): void
    {
        $crawler = $this->page();

        self::assertSame('I have a code', $crawler->filter('nav[aria-label="Main"] a')->text());
        self::assertCount(0, $crawler->filter('nav[aria-label="Main"] a[href="/register"]'));
        self::assertSame('Sign in with your code', $crawler->filter('#recovery-link a')->text());
        self::assertCount(1, $crawler->filter('footer'));
    }

    public function testComingFromAPlaneKeepsTheWayBack(): void
    {
        $plane = '0195f3a0-7c11-7b3e-9f6a-2d4c8e1a5b90';
        $crawler = $this->page("/register?plane=$plane");

        self::assertSame("/recover?plane=$plane", $crawler->filter('#recovery-link a')->attr('href'));
    }

    public function testTheOldLookIsGone(): void
    {
        $crawler = $this->page();
        $html = $crawler->filter('main')->html();

        foreach (['amber-', 'from-white', 'sky-600', 'sky-700', 'rounded-3xl'] as $old) {
            self::assertStringNotContainsString($old, $html);
        }
        self::assertCount(0, $crawler->filter('.rounded-full.bg-white\/55'), 'no loose clouds');
    }

    public function testTheCodeScreenWaitsHiddenAndEmpty(): void
    {
        $section = $this->page()->filter('section#identity-created');

        self::assertNotNull($section->attr('hidden'));
        self::assertSame('/', $section->attr('data-continue-url'));
        self::assertSame('', $section->filter('output#created-code')->text());
        self::assertSame('Copy code', $section->filter('#copy')->text());
        self::assertSame('Download .txt', $section->filter('#download')->text());
        self::assertStringContainsString("I've saved my code somewhere safe", $section->filter('label')->text());

        // No way forward until the checkbox says the code is saved.
        self::assertSame('Continue to the sky', $section->filter('#continue')->text());
        self::assertNotNull($section->filter('#continue')->attr('disabled'));
    }

    public function testContinuingGoesBackToThePlaneItCameFrom(): void
    {
        $plane = '0195f3a0-7c11-7b3e-9f6a-2d4c8e1a5b90';

        self::assertSame("/?plane=$plane", $this->page("/register?plane=$plane")->filter('#identity-created')->attr('data-continue-url'));
    }

    /** The code is a secret: the scripts that handle it never put it in browser storage, logs or a URL. */
    public function testScriptsNeverPersistTheCode(): void
    {
        foreach (['registration.js', 'recovery.js'] as $script) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/js/'.$script);

            self::assertDoesNotMatchRegularExpression('/localStorage|sessionStorage|document\.cookie|console\.|umami|history\.|URLSearchParams/', $source, $script);
        }
    }

    public function testThePageIsNeverCached(): void
    {
        $this->page();

        self::assertStringContainsString('no-store', (string) static::getClient()->getResponse()->headers->get('Cache-Control'));
    }
}
