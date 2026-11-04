<?php

declare(strict_types=1);

namespace App\Helper;

use Symfony\Component\Uid\Uuid;

/**
 * Picks the colour of a stamp: "random" but stable, derived from the stamp's own uuid so it never
 * changes between loads (and never needs to be stored). Every colour keeps white text readable.
 */
final readonly class StampColorHelper
{
    public const array PALETTE = ['#C2410C', '#2F5DA8', '#0F766E', '#7C3AED', '#BE185D', '#14213D', '#4D7C0F', '#0E7490'];

    public function colorFor(Uuid $stampUuid): string
    {
        return self::PALETTE[crc32($stampUuid->toRfc4122()) % \count(self::PALETTE)];
    }
}
