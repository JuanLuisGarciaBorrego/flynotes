<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;

/** FlyNotes speaks English: the copy of the templates and scripts, and the language the pages declare. */
class EnglishExperienceTest extends WebTestCase
{
    /** Spanish punctuation and letters, and the words of this domain. "é" is left out: English borrows it. */
    private const string SPANISH = '/[áíóúñ¿¡]|\b(misi[oó]n|avi[oó]n|aviones|rechazar|publicar|autorizar|investigaci[oó]n|humanos)\b/iu';

    /** The footer credit: a proper name keeps its accent, it is not copy to translate. */
    private const string AUTHOR = 'Juan Luis García';

    public function testNoSpanishInTemplatesOrScripts(): void
    {
        $root = \dirname(__DIR__, 2);

        foreach (new Finder()->files()->in([$root.'/templates', $root.'/assets/js'])->name(['*.twig', '*.js']) as $file) {
            self::assertDoesNotMatchRegularExpression(self::SPANISH, str_replace(self::AUTHOR, '', $file->getContents()), $file->getRelativePathname());
        }
    }

    public function testPublicPagesDeclareEnglish(): void
    {
        $client = static::createClient();

        foreach (['/', '/sky-pulse', '/agents', '/register'] as $path) {
            $crawler = $client->request('GET', $path);

            self::assertResponseIsSuccessful($path);
            self::assertSame('en', $crawler->filter('html')->attr('lang'), $path);
        }
    }
}
