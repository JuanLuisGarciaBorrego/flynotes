<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/** A test that forgets to replace the provider fails locally: it never reaches it. */
class ProviderIsolationTest extends KernelTestCase
{
    public function testTheProviderIsUnreachableInTheTestEnvironment(): void
    {
        self::assertSame('127.0.0.1', parse_url((string) ($_SERVER['GENERIC_BASE_URL'] ?? ''), \PHP_URL_HOST));
    }

    public function testAnAgentWithoutAReplacedPlatformFailsWithoutAnyAnswer(): void
    {
        self::bootKernel();

        try {
            self::getContainer()->get('ai.agent.paper_plane')->call('Hola')->getContent();
            self::fail('The provider must not answer in tests.');
        } catch (TransportExceptionInterface|PlatformException $exception) {
            self::assertStringNotContainsString('Authentication failed', $exception->getMessage());
        }
    }
}
