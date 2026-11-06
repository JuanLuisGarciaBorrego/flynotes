<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Human identity: session login on registration/recovery, recovery by code, rate limit and CSRF. */
class IdentityRecoveryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // Each test is a distinct "origin" so the persisted rate limiter state never leaks between tests/runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $uri, array $body, array $extraServer = []): array
    {
        $this->client->request($method, $uri, server: $extraServer + ParticipantApiTest::jsonLd(), content: json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function register(string $name = 'Marta'): array
    {
        $data = $this->json('POST', '/api/participants', ['name' => $name, 'countryCode' => 'ES']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $data;
    }

    private function recover(string $code, array $extraServer = []): array
    {
        return $this->json('POST', '/api/participants/recover', ['code' => $code], $extraServer);
    }

    private function newBrowser(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function createPlane(string $message = 'Hola desde FlyNotes'): void
    {
        $this->json('POST', '/api/paper_planes', ['message' => $message]);
    }

    public function testRegistrationCreatesAuthenticatedPersistentSession(): void
    {
        $data = $this->register();

        self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{4}(-[2-9A-HJ-NP-Z]{4}){3}$/', $data['code']);
        self::assertNotEmpty($data['emoji']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));

        $cookie = $this->client->getResponse()->headers->getCookies()[0];
        self::assertTrue($cookie->isHttpOnly());
        self::assertGreaterThan(time() + 29 * 86400, $cookie->getExpiresTime());

        $this->createPlane();
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testRecoveryWithValidCodeFromAnotherBrowser(): void
    {
        $data = $this->register('Ana');
        $this->newBrowser();

        $this->createPlane();
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Lowercase and without dashes is normalized.
        $recovered = $this->recover(strtolower(str_replace('-', '', $data['code'])));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('Ana', $recovered['name']);
        self::assertSame($data['emoji'], $recovered['emoji']);
        self::assertSame($data['uuid'], $recovered['uuid']);
        // Same output as the registration; the code comes back in its canonical form.
        $keys = array_keys(array_filter($recovered, static fn (string $key): bool => !str_starts_with($key, '@'), \ARRAY_FILTER_USE_KEY));
        sort($keys);
        self::assertSame(['code', 'countryCode', 'emoji', 'name', 'uuid'], $keys);
        self::assertSame($data['code'], $recovered['code']);
        self::assertStringNotContainsString($data['code'], (string) $this->client->getResponse()->headers->get('Set-Cookie'));
        self::assertTrue($this->client->getResponse()->headers->getCookies()[0]->isHttpOnly());

        $this->createPlane('Desde otro dispositivo');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Ana', json_decode($this->client->getResponse()->getContent(), true)['authorName']);
    }

    public function testRecoveryIgnoresRegistrationFieldsAndOnlyValidatesTheCode(): void
    {
        $data = $this->register('Ana');
        $this->newBrowser();

        $recovered = $this->json('POST', '/api/participants/recover', ['code' => $data['code'], 'name' => 'Otra', 'countryCode' => 'JP']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('Ana', $recovered['name']);
        self::assertSame($data['countryCode'], $recovered['countryCode']);

        $invalid = $this->json('POST', '/api/participants/recover', ['code' => '  ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['code'], array_column($invalid['violations'], 'propertyPath'));
    }

    public function testRecoveryDoesNotCreateParticipants(): void
    {
        $this->register();
        $before = $this->em->getRepository(\App\Entity\Participant::class)->count([]);

        $this->recover('AAAA-AAAA-AAAA-AAAA');

        self::assertSame($before, $this->em->getRepository(\App\Entity\Participant::class)->count([]));
    }

    public function testWrongBotAndMalformedCodesAreIndistinguishable(): void
    {
        $bot = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Robo');

        $responses = [];
        foreach (['AAAA-AAAA-AAAA-AAAA', $bot->getCode(), 'not a code'] as $code) {
            $responses[] = $this->recover($code);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            self::assertArrayNotHasKey('uuid', $responses[array_key_last($responses)]);
            // Not authenticated afterwards.
            $this->createPlane();
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
        self::assertSame($responses[0]['detail'], $responses[1]['detail']);
        self::assertSame($responses[0]['detail'], $responses[2]['detail']);

        $this->recover('');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRecoveryIsRateLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->recover('AAAA-AAAA-AAAA-AAAA');
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        }

        $this->recover('AAAA-AAAA-AAAA-AAAA');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testRecoveryLimitIsPerOrigin(): void
    {
        $data = $this->register();
        for ($i = 0; $i < 5; ++$i) {
            $this->recover('AAAA-AAAA-AAAA-AAAA');
        }
        $this->recover($data['code']);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->recover($data['code']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testRecoveryCodeIsNotACookieCredential(): void
    {
        $data = $this->register();
        $this->newBrowser();

        // Bearer is covered by ParticipantApiBearerTest; the code alone never works as a cookie.
        $this->createPlane();
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('flynotes_identity', $data['code']));
        $this->createPlane();
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testClientCannotChooseBotType(): void
    {
        $data = $this->json('POST', '/api/participants', ['name' => 'Evil', 'countryCode' => 'ES', 'type' => 'bot']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $participant = $this->em->getRepository(\App\Entity\Participant::class)->findOneBy(['code' => $data['code']]);
        self::assertSame(ParticipantType::Human, $participant->getType());
    }

    public function testOrdinaryResponsesDoNotExposeTheCode(): void
    {
        $data = $this->register();
        $this->createPlane('Mensaje muy secreto');
        $uuid = json_decode($this->client->getResponse()->getContent(), true)['uuid'];

        foreach (['/api/paper_planes', '/api/paper_planes/'.$uuid, '/', '/my-planes', '/recover'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/ld+json']);
            self::assertStringNotContainsString($data['code'], $this->client->getResponse()->getContent(), $path);
        }
        $this->client->request('GET', '/register');
        self::assertStringNotContainsString($data['code'], $this->client->getResponse()->getContent());
    }

    public function testRecoverPageIsRenderedAndLinked(): void
    {
        $this->client->request('GET', '/recover');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#recovery-form input[name="code"]');
        self::assertSelectorTextContains('#recovery-form button', 'Sign in');
        self::assertSelectorExists('a[href="/register"]');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', '/register');
        self::assertSelectorExists('a[href="/recover"]');
    }

    public function testIHaveACodeLinksGoToTheRecoverPage(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // The plane dialog's link (data-base-url) gets its href from JS: /recover?plane=<uuid>.
        $links = $crawler->filter('a:not([data-base-url])')->reduce(static fn ($a): bool => 'I have a code' === trim($a->text()));
        self::assertCount(2, $links, 'Header (desktop) and hero (mobile) links.');
        foreach ($links as $link) {
            self::assertSame('/recover', $link->getAttribute('href'));
        }
    }

    public function testAnonymousReadsDoNotStartASession(): void
    {
        $this->client->request('GET', '/api/paper_planes', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testCsrfIsRequiredOnApiWrites(): void
    {
        $data = $this->register();
        $bare = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];

        $writes = [
            ['/api/participants', ['name' => 'Otra']],
            ['/api/participants/recover', ['code' => $data['code']]],
            ['/api/paper_planes', ['message' => 'Hola desde FlyNotes']],
        ];
        foreach ($writes as [$uri, $body]) {
            // Authenticated by the session cookie, but without CSRF proof: refused, nothing is processed.
            $this->client->request('POST', $uri, server: $bare, content: json_encode($body));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $uri);

            // Token too short to be a valid proof.
            $this->client->request('POST', $uri, server: $bare + ['HTTP_CSRF_TOKEN' => 'short'], content: json_encode($body));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $uri);

            // Cross-site request, even carrying the header.
            $this->client->request('POST', $uri, server: ParticipantApiTest::jsonLd() + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], content: json_encode($body));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $uri);
            $this->client->request('POST', $uri, server: ParticipantApiTest::jsonLd() + ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/page'], content: json_encode($body));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $uri);
        }

        // Same-origin browser requests with the header work.
        $this->client->request('POST', '/api/paper_planes', server: ParticipantApiTest::jsonLd() + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], content: '{"message":"Hola desde FlyNotes"}');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->client->request('POST', '/api/paper_planes', server: ParticipantApiTest::jsonLd() + ['HTTP_ORIGIN' => 'http://localhost'], content: '{"message":"Hola desde FlyNotes"}');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testSafeReadsDoNotNeedCsrf(): void
    {
        $this->client->request('GET', '/api/paper_planes', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
    }
}
