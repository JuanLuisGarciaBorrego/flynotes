<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use App\Ai\Tool\GetSkyCountries;
use App\Repository\ParticipantRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GetSkyCountriesTest extends TestCase
{
    /** @return iterable<string, array{array<string, int>, array<string, int>}> real counts, result */
    public static function countries(): iterable
    {
        yield 'nobody' => [[], []];
        yield 'one participant is listed' => [['DE' => 1], ['DE' => 1]];
        yield 'small and large countries are all listed' => [
            ['ES' => 40, 'FR' => 18, 'IT' => 2, 'DE' => 1],
            ['ES' => 40, 'FR' => 18, 'IT' => 2, 'DE' => 1],
        ];
    }

    /**
     * @param array<string, int> $real
     * @param array<string, int> $expected
     */
    #[DataProvider('countries')]
    public function testEveryCountryIsListedWithItsRealCount(array $real, array $expected): void
    {
        $repository = $this->createStub(ParticipantRepository::class);
        $repository->method('countByCountry')->willReturn(self::rows($real));

        self::assertSame(self::rows($expected), new GetSkyCountries($repository)());
    }

    /**
     * @param array<string, int> $totals
     *
     * @return list<array{countryCode: string, total: int}>
     */
    private static function rows(array $totals): array
    {
        return array_map(
            static fn (string $code, int $total): array => ['countryCode' => $code, 'total' => $total],
            array_keys($totals),
            array_values($totals),
        );
    }
}
