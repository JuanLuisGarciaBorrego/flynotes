<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PaperPlane;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use App\Tests\Api\ParticipantApiTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /planes/new: the form's limits and counter come from App\Entity\PaperPlane, the same constants the
 * API (ApiResource\PaperPlane) and MCP validate with. Boundary cases of the API: ParticipantApiTest.
 */
class PaperPlaneNewControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES'), 'main');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testFieldLimitsComeFromTheEntity(): void
    {
        $crawler = $this->client->request('GET', '/planes/new');

        self::assertResponseIsSuccessful();
        $field = $crawler->filter('#paper-plane-form textarea#message');
        self::assertSame((string) PaperPlane::MESSAGE_MIN_LENGTH, $field->attr('minlength'));
        // No maxlength: the browser counts UTF-16 units (an emoji is two) and would block valid messages.
        // The maximum goes to JavaScript, which counts like the validator (trimmed, code points).
        self::assertNull($field->attr('maxlength'));
        self::assertSame((string) PaperPlane::MESSAGE_MAX_LENGTH, $field->attr('data-max-length'));
        self::assertStringContainsString('message-count', (string) $field->attr('aria-describedby'));
    }

    public function testTheSheetSaysWhoItIsFromAndWhereItGoes(): void
    {
        $crawler = $this->client->request('GET', '/planes/new');

        self::assertSelectorTextSame('h1', 'What do you want to send into the world?');
        $sheet = $crawler->filter('form#paper-plane-form');
        // The flag comes from the code, in assets/js/paper-plane.js; the country's name stays as the code's title.
        self::assertStringContainsString('Autora · ES', $sheet->text());
        self::assertSame('ES', $sheet->filter('#from-flag')->attr('data-country-code'));
        self::assertSame('Spain', $sheet->filter('abbr')->attr('title'));
        self::assertStringContainsString('TO someone, somewhere', $sheet->text());
        self::assertSame('Your message', $sheet->filter('label[for="message"]')->text());
        self::assertStringContainsString('sr-only', (string) $sheet->filter('label[for="message"]')->attr('class'));
        self::assertSame('Write something someone, somewhere, might need to read today…', $sheet->filter('#message')->attr('placeholder'));
        self::assertStringNotContainsString('✈️', (string) $crawler->filter('#paper-plane-new')->html());
        self::assertStringContainsString("Once it takes off, it can't be edited.", $crawler->filter('#paper-plane-new')->text());
    }

    /** With no text there is nothing to launch, and the sparks offer a way to start. */
    public function testAnEmptySheetCannotBeLaunchedAndOffersSparks(): void
    {
        $crawler = $this->client->request('GET', '/planes/new');

        $button = $crawler->filter('#submit');
        self::assertNotNull($button->attr('disabled'));
        self::assertSame('submit', $button->attr('type'));
        self::assertSame('Fold & launch', $button->text());
        self::assertSame(
            ['To whoever reads this: ', 'I still remember ', 'Today I finally '],
            $crawler->filter('#message-sparks [data-spark]')->each(static fn ($spark): ?string => $spark->attr('data-spark')),
        );
        self::assertSame(['Something kind', 'A memory', 'A small win'], $crawler->filter('#message-sparks [data-spark]')->each(static fn ($spark): string => $spark->text()));
    }

    /** Each spark starts with a message that could be sent as it is, so it never leads to an error by itself. */
    public function testSparksAreLongEnoughToBeSent(): void
    {
        $crawler = $this->client->request('GET', '/planes/new');

        foreach ($crawler->filter('#message-sparks [data-spark]') as $spark) {
            self::assertGreaterThanOrEqual(PaperPlane::MESSAGE_MIN_LENGTH, mb_strlen(trim($spark->getAttribute('data-spark'))));
        }
    }

    /** JetBrains Mono is only for technical labels: a page without any, like the home, does not pay for it. */
    public function testTheLabelFontIsOnlyLoadedWhereItIsUsed(): void
    {
        $this->client->request('GET', '/planes/new');
        self::assertStringContainsString('family=JetBrains+Mono:wght@500;700', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/');
        self::assertStringNotContainsString('JetBrains', (string) $this->client->getResponse()->getContent());
    }

    /** One version at a time: a new one replaces the previous, with no list of versions or counter next to it. */
    public function testTheAiDialogShowsOneVersionAtATime(): void
    {
        $dialog = $this->client->request('GET', '/planes/new')->filter('dialog#ai-dialog');

        foreach (['#ai-version-picker', '#ai-version-options', '#ai-version-option', '#ai-status', '#ai-attempts'] as $gone) {
            self::assertCount(0, $dialog->filter($gone), $gone);
        }
        self::assertStringContainsString('h-11 w-11', (string) $dialog->filter('[data-ai-close]')->attr('class'));
    }

    /** The AI review dialog ships closed; assets/js/paper-plane.js opens it (showModal) once the plane is launched. */
    public function testAiReviewDialogIsReadyButClosed(): void
    {
        $crawler = $this->client->request('GET', '/planes/new');

        // The dialog lives in the body only (it once leaked into the <title> block as text).
        self::assertSame('New plane · FlyNotes', $crawler->filter('title')->text());
        $dialog = $crawler->filter('dialog#ai-dialog');
        self::assertCount(1, $dialog);
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), '<dialog id="ai-dialog"'));
        self::assertNull($dialog->attr('open'));
        self::assertSame('ai-title', $dialog->attr('aria-labelledby'));
        self::assertSame('The AI wrote its own take. Should it fly too?', $dialog->filter('#ai-title')->text());
        // Keyboard order: reject (left), then another version and approve (right).
        self::assertSame(['reject', 'retry', 'accept'], $dialog->filter('[data-ai-action]')->each(static fn ($button): string => (string) $button->attr('data-ai-action')));
        self::assertSame('No, only mine', $dialog->filter('[data-ai-action="reject"]')->text());
        self::assertSame('Let it fly too', $dialog->filter('[data-ai-action="accept"]')->text());
        self::assertSame('polite', $dialog->filter('#ai-proposal')->attr('aria-live'));
        // Blocking loading state: rotating status, three skeleton bars, the time hint and the hold notice.
        self::assertSame('status', $dialog->filter('#ai-generating-step')->attr('role'));
        self::assertCount(3, $dialog->filter('#ai-generating .ai-skeleton'));
        self::assertStringContainsString('This can take up to 30 seconds.', $dialog->filter('#ai-generating')->text());
        self::assertStringContainsString("Hold on, the AI is writing. You can decide as soon as it's ready.", $dialog->filter('#ai-hold')->text());
        self::assertSame('alert', $dialog->filter('#ai-error')->attr('role'));
        self::assertStringContainsString('Nothing written by the AI flies without your OK.', $dialog->text());
        self::assertSame(['/', '/'], $dialog->filter('[data-ai-view] a')->each(static fn ($link): string => (string) $link->attr('href')));
        self::assertSame(['Both versions are flying', 'Only yours is flying'], $dialog->filter('[data-ai-final-title]')->each(static fn ($title): string => $title->text()));
    }

    public function testCounterStartsWithAllCharactersLeftAndIsAnnounced(): void
    {
        $crawler = $this->client->request('GET', '/planes/new');

        $counter = $crawler->filter('#message-count');
        self::assertSame(PaperPlane::MESSAGE_MAX_LENGTH.' characters left', $counter->text());
        self::assertSame('polite', $counter->attr('aria-live'));
        self::assertStringNotContainsString('1000', (string) $this->client->getResponse()->getContent());
    }

    /**
     * What the form sends (POST /api/paper_planes, trimmed by the script) at the limits, counted like
     * paper-plane.js messageLength(): code points after trim, so emojis do not hit the maximum early.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function messages(): iterable
    {
        $max = PaperPlane::MESSAGE_MAX_LENGTH;
        yield 'maximum' => [str_repeat('a', $max), Response::HTTP_CREATED];
        yield 'one over the maximum' => [str_repeat('a', $max + 1), Response::HTTP_UNPROCESSABLE_ENTITY];
        // 250 emojis are 500 UTF-16 units: a maxlength attribute would have stopped them at 125.
        yield 'maximum in emojis' => [str_repeat('😀', $max), Response::HTTP_CREATED];
        yield 'one emoji over the maximum' => [str_repeat('😀', $max + 1), Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'maximum with surrounding spaces' => ['  '.str_repeat('a', $max).' ', Response::HTTP_CREATED];
        yield 'minimum with surrounding spaces' => [' '.str_repeat('a', PaperPlane::MESSAGE_MIN_LENGTH).'  ', Response::HTTP_CREATED];
        yield 'below the minimum once trimmed' => ['   '.str_repeat('a', PaperPlane::MESSAGE_MIN_LENGTH - 1).'   ', Response::HTTP_UNPROCESSABLE_ENTITY];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('messages')]
    public function testTheFormsApiAppliesTheSameLimits(string $message, int $status): void
    {
        $this->client->request('POST', '/api/paper_planes', server: ParticipantApiTest::jsonLd(), content: json_encode(['message' => $message], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($status);
        if (Response::HTTP_CREATED === $status) {
            self::assertSame(trim($message), json_decode((string) $this->client->getResponse()->getContent(), true)['message']);
        }
    }
}
