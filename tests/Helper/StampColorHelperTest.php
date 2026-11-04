<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\StampColorHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StampColorHelperTest extends TestCase
{
    public function testColorIsStableAndFollowsTheCrc32Rule(): void
    {
        $uuid = Uuid::fromString('0198f1a2-7b3c-7d4e-8f50-123456789abc');
        $helper = new StampColorHelper();

        $expected = StampColorHelper::PALETTE[crc32($uuid->toRfc4122()) % 8];
        self::assertSame($expected, $helper->colorFor($uuid));
        self::assertSame($helper->colorFor($uuid), $helper->colorFor(Uuid::fromString($uuid->toRfc4122())));
    }

    public function testPaletteHasEightColors(): void
    {
        self::assertCount(8, StampColorHelper::PALETTE);
    }
}
