<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The footer links to the API docs and to the /agents page that presents the MCP integration.
 */
class FooterTest extends WebTestCase
{
    public function testFooterLinksToApiDocsAndMcp(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $apiLink = $crawler->filter('footer a[href="/api"]');
        self::assertCount(1, $apiLink);
        self::assertStringStartsWith('API', trim($apiLink->text()));

        foreach (['/api', 'https://symfony.com/hackathon/projects/flynotes'] as $href) {
            self::assertSame('_blank', $crawler->filter(\sprintf('footer a[href="%s"]', $href))->attr('target'));
        }
        $github = $crawler->filter('footer nav a')->first();
        self::assertSame('https://github.com/JuanLuisGarciaBorrego/flynotes', $github->attr('href'));
        self::assertSame('_blank', $github->attr('target'));

        // Internal page: same tab. The raw /mcp endpoint is for MCP clients, not for browsers.
        $mcpLink = $crawler->filter('footer a[href="/agents"]');
        self::assertCount(1, $mcpLink);
        self::assertSame('MCP', trim($mcpLink->text()));
        self::assertNull($mcpLink->attr('target'));
        self::assertCount(0, $crawler->filter('footer a[href="/mcp"]'));
    }

    public function testFooterCreditsTheAuthor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $footer = $crawler->filter('footer');
        self::assertStringContainsString('A project by Juan Luis García', $footer->text());
        self::assertStringNotContainsString('VitaminaTech', $footer->text());
        self::assertStringContainsString('Where humans and AI share the sky.', $footer->text());
        self::assertStringNotContainsString('One sky, two minds', $footer->text());
        self::assertStringNotContainsString('🧠', $footer->text());
        self::assertStringNotContainsString('🤖', $footer->text());

        $link = $footer->filter('a[href="https://www.linkedin.com/in/juanluisgarciaborrego/"]');
        self::assertStringStartsWith('Juan Luis García', $link->text());
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener noreferrer', $link->attr('rel'));
    }

    public function testApiLinkServesDocs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseIsSuccessful();
    }
}
