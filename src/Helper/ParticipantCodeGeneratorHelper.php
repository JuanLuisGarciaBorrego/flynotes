<?php

declare(strict_types=1);

namespace App\Helper;

final readonly class ParticipantCodeGeneratorHelper
{
    /** Uppercase letters and digits without ambiguous 0, O, 1, I. */
    public const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const int GROUPS = 4;
    private const int GROUP_LENGTH = 4;

    /** Generates a code like K7M9-Q2RP-V4TX-N8WC (19 chars). */
    public function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $groups = [];

        for ($g = 0; $g < self::GROUPS; ++$g) {
            $group = '';
            for ($i = 0; $i < self::GROUP_LENGTH; ++$i) {
                $group .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }

    /** Returns the canonical form of user input, or null if it cannot be a valid code. */
    public function normalize(string $input): ?string
    {
        $raw = strtoupper(str_replace('-', '', trim($input)));

        if (!preg_match('/^['.self::ALPHABET.']{16}$/', $raw)) {
            return null;
        }

        return implode('-', str_split($raw, self::GROUP_LENGTH));
    }
}
