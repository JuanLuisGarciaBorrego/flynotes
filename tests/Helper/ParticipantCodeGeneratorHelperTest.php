<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\ParticipantCodeGeneratorHelper;
use PHPUnit\Framework\TestCase;

class ParticipantCodeGeneratorHelperTest extends TestCase
{
    public function testFormatAndAlphabet(): void
    {
        $generator = new ParticipantCodeGeneratorHelper();

        for ($i = 0; $i < 200; ++$i) {
            $code = $generator->generate();
            self::assertSame(19, strlen($code));
            self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{4}(-[2-9A-HJ-NP-Z]{4}){3}$/', $code);
            self::assertDoesNotMatchRegularExpression('/[01OI]/', $code);
        }
    }

    public function testCodesAreNotRepeated(): void
    {
        $generator = new ParticipantCodeGeneratorHelper();
        $codes = array_map(fn () => $generator->generate(), range(1, 500));

        self::assertCount(500, array_unique($codes));
    }

    public function testNormalize(): void
    {
        $generator = new ParticipantCodeGeneratorHelper();

        self::assertSame('K7M9-Q2RP-V4TX-N8WC', $generator->normalize('  k7m9q2rpv4txn8wc '));
        self::assertSame('K7M9-Q2RP-V4TX-N8WC', $generator->normalize('k7m9-q2rp-v4tx-n8wc'));
        self::assertNull($generator->normalize('K7M9-Q2RP'));
        self::assertNull($generator->normalize('K7M0-Q2RP-V4TX-N8WC')); // ambiguous char
        self::assertNull($generator->normalize(''));
    }
}
