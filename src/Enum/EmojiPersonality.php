<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Component\Emoji\EmojiTransliterator;

/**
 * The emoji a participant gets at registration, and the fictional personality it stands for.
 * It is a humorous character, never the author's real state of mind.
 *
 * The backing value is the emoji itself: it is what `participant.emoji` stores, so nothing else is persisted.
 */
enum EmojiPersonality: string
{
    case Happy = '😄';
    case Sad = '😢';
    case Crazy = '🤪';
    case InLove = '🥰';
    case Angry = '😡';
    case Cool = '😎';
    case Moved = '🥹';
    case Dramatic = '😱';
    case PartyAnimal = '🥳';
    case Thoughtful = '🤔';
    case Sleepy = '😴';
    case Tender = '🥺';
    case Cheeky = '😏';
    case KnowItAll = '🤓';
    case Sarcastic = '🙄';
    case OverTheTop = '🤯';

    /** Random personality for a new participant. */
    public static function random(): self
    {
        $cases = self::cases();

        return $cases[random_int(0, \count($cases) - 1)];
    }

    /** Short name of the personality, in English (what the author sees). */
    public function label(): string
    {
        return match ($this) {
            self::Happy => 'Happy',
            self::Sad => 'Sad',
            self::Crazy => 'Crazy',
            self::InLove => 'In love',
            self::Angry => 'Angry',
            self::Cool => 'Cool',
            self::Moved => 'Moved',
            self::Dramatic => 'Dramatic',
            self::PartyAnimal => 'Party animal',
            self::Thoughtful => 'Thoughtful',
            self::Sleepy => 'Sleepy',
            self::Tender => 'Tender',
            self::Cheeky => 'Cheeky',
            self::KnowItAll => 'Know-it-all',
            self::Sarcastic => 'Sarcastic',
            self::OverTheTop => 'Over the top',
        };
    }

    /** Official Unicode (CLDR) name of the emoji, e.g. "crying face". Not the personality: see label(). */
    public function unicodeName(string $locale = 'en'): string
    {
        return EmojiTransliterator::create('emoji-'.$locale)->transliterate($this->value);
    }
}
