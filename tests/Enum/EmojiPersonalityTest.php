<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Participant;
use App\Enum\EmojiPersonality;
use PHPUnit\Framework\TestCase;

class EmojiPersonalityTest extends TestCase
{
    public function testTheSixteenEmotionEmojisInOrder(): void
    {
        self::assertSame(
            ['😄', '😢', '🤪', '🥰', '😡', '😎', '🥹', '😱', '🥳', '🤔', '😴', '🥺', '😏', '🤓', '🙄', '🤯'],
            array_column(EmojiPersonality::cases(), 'value'),
        );
    }

    public function testEveryPersonalityHasItsOwnLabel(): void
    {
        $labels = array_map(static fn (EmojiPersonality $p): string => $p->label(), EmojiPersonality::cases());
        self::assertCount(16, array_unique($labels));
        self::assertSame('Sad', EmojiPersonality::Sad->label());
    }

    public function testUnicodeNameComesFromSymfonyEmoji(): void
    {
        self::assertSame('crying face', EmojiPersonality::Sad->unicodeName());
        self::assertSame('cara llorando', EmojiPersonality::Sad->unicodeName('es'));
        foreach (EmojiPersonality::cases() as $personality) {
            self::assertNotSame($personality->value, $personality->unicodeName(), $personality->name);
        }
    }

    public function testRandomIsAlwaysOneOfTheCases(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            self::assertContains(EmojiPersonality::random(), EmojiPersonality::cases());
        }
    }

    public function testParticipantDerivesThePersonalityFromItsEmoji(): void
    {
        self::assertSame(EmojiPersonality::Cool, new Participant()->setEmoji('😎')->getPersonality());
        // An animal from before the migration has no personality (and nothing breaks).
        self::assertNull(new Participant()->setEmoji('🦊')->getPersonality());
        self::assertNull(new Participant()->getPersonality());
    }
}
