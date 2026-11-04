<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PaperPlane;
use App\Enum\ParticipantType;
use App\Repository\ParticipantRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ParticipantApiTest extends WebTestCase
{
    /** Server params of a same-origin JSON-LD write, as sent by assets/js/api.js (random `csrf-token` header). */
    public static function jsonLd(): array
    {
        return [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ];
    }

    private function post(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $body): void
    {
        $client->request('POST', '/api/participants', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testRegisterHuman(): void
    {
        $client = static::createClient();
        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->beginTransaction();

        // Internal fields sent by the client must be ignored.
        $this->post($client, ['name' => ' Explorador ', 'countryCode' => 'ES', 'type' => 'bot', 'uuid' => 'x', 'emoji' => 'X', 'code' => 'AAAA-AAAA-AAAA-AAAA']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($data);
        sort($keys);
        self::assertSame(['code', 'countryCode', 'emoji', 'name', 'uuid'], $keys);
        self::assertSame('Explorador', $data['name']);
        self::assertSame('ES', $data['countryCode']);
        self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{4}(-[2-9A-HJ-NP-Z]{4}){3}$/', $data['code']);
        self::assertNotSame('X', $data['emoji']);
        self::assertNotSame('x', $data['uuid']);

        $participant = static::getContainer()->get(ParticipantRepository::class)->findOneBy(['code' => $data['code']]);
        self::assertNotNull($participant);
        self::assertSame(ParticipantType::Human, $participant->getType());
        self::assertSame($data['uuid'], $participant->getUuid()->toRfc4122());

        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->rollBack();
    }

    public function testCountryCodeIsRequired(): void
    {
        $client = static::createClient();

        foreach ([['name' => 'Ana'], ['name' => 'Ana', 'countryCode' => null], ['name' => 'Ana', 'countryCode' => ''], ['name' => 'Ana', 'countryCode' => '  ']] as $body) {
            $this->post($client, $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('countryCode', json_decode($client->getResponse()->getContent(), true)['violations'][0]['propertyPath']);
        }
    }

    public function testInvalidNames(): void
    {
        $client = static::createClient();

        foreach ([[], ['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 256)], ['name' => 'Ana', 'countryCode' => 'ESP'], ['name' => 'Ana', 'countryCode' => 'E1'], ['name' => 'Ana', 'countryCode' => 'es'], ['name' => 'Ana', 'countryCode' => 'ZZ']] as $body) {
            $this->post($client, $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function testOtherMethodsAreNotExposed(): void
    {
        $client = static::createClient();

        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $client->request($method, '/api/participants');
            self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED, $method);
        }
        $client->request('GET', '/api/participants/0190a1b2-0000-7000-8000-000000000000', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTwigPagesStillWork(): void
    {
        $client = static::createClient();
        foreach (['/', '/register'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testRegistrationPageAndIdentityCookie(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('option[value="ES"]')->count());
        self::assertStringContainsString('Spain', $crawler->filter('option[value="ES"]')->text());
        self::assertSame(0, $crawler->filter('option[value="ZZ"]')->count());

        $client->disableReboot();
        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->beginTransaction();
        $client->request('POST', '/api/participants', server: self::jsonLd(), content: json_encode(['name' => 'Ana', 'countryCode' => 'ES'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Ana', $data['name']);
        $cookie = $client->getResponse()->headers->getCookies()[0];
        self::assertSame('MOCKSESSID', $cookie->getName()); // mock_file storage in the test env
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertNotSame($data['code'], $cookie->getValue());
        self::assertStringNotContainsString($data['code'], (string) $client->getResponse()->headers->get('Set-Cookie'));

        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->rollBack();
    }

    public function testNavShowsRegisterOnlyWhenAnonymous(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->beginTransaction();

        $client->request('GET', '/');
        self::assertSelectorExists('a[href="/register"]');

        $client->request('POST', '/api/participants', server: self::jsonLd(), content: json_encode(['name' => 'Marta', 'countryCode' => 'ES'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/');
        self::assertSelectorNotExists('a[href="/register"]');
        self::assertSelectorTextContains('nav', 'Marta');

        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->rollBack();
    }

    public function testPaperPlaneCreationRequiresIdentity(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->beginTransaction();
        $json = self::jsonLd();

        $client->request('GET', '/planes/new');
        self::assertResponseRedirects('/register');
        $client->request('POST', '/api/paper_planes', server: $json, content: '{"message":"Hola"}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', '/api/participants', server: $json, content: '{"name":"Marta","countryCode":"ES"}');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/planes/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/paper_planes', server: $json, content: '{"message":"  "}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Minimum length is 15 characters, counted after trimming.
        $client->request('POST', '/api/paper_planes', server: $json, content: '{"message":"12345678901234"}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('message', json_decode($client->getResponse()->getContent(), true)['violations'][0]['propertyPath']);
        $client->request('POST', '/api/paper_planes', server: $json, content: '{"message":"  12345678901234 "}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $client->request('POST', '/api/paper_planes', server: $json, content: '{"message":"123456789012345"}');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Maximum length is 250 characters, also counted after trimming.
        $maxLength = PaperPlane::MESSAGE_MAX_LENGTH;
        $client->request('POST', '/api/paper_planes', server: $json, content: json_encode(['message' => str_repeat('a', $maxLength + 1)], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('message', json_decode($client->getResponse()->getContent(), true)['violations'][0]['propertyPath']);
        $client->request('POST', '/api/paper_planes', server: $json, content: json_encode(['message' => str_repeat('a', $maxLength)], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $client->request('POST', '/api/paper_planes', server: $json, content: json_encode(['message' => ' '.str_repeat('b', $maxLength).' '], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(str_repeat('b', $maxLength), json_decode($client->getResponse()->getContent(), true)['message']);

        $client->request('POST', '/api/paper_planes', server: $json, content: '{"message":"Hola mundo, qué tal"}');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Hola mundo, qué tal', json_decode($client->getResponse()->getContent(), true)['message']);

        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->rollBack();
    }

    public function testPublicRandomFeedReturnsAtMostThirty(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->beginTransaction();
        $json = self::jsonLd();

        $client->request('POST', '/api/participants', server: $json, content: '{"name":"Marta","countryCode":"ES"}');
        $code = json_decode($client->getResponse()->getContent(), true)['code'];
        for ($i = 1; $i <= 12; ++$i) {
            $client->request('POST', '/api/paper_planes', server: self::jsonLd(), content: json_encode(['message' => "Mensaje número $i de prueba"], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        $client->getCookieJar()->clear();
        $client->request('GET', '/api/paper_planes', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        $members = json_decode($client->getResponse()->getContent(), true)['member'];
        self::assertCount(12, $members); // fewer planes than the cap of 30: all of them
        // The list only reveals uuids.
        self::assertSame(['@id', '@type', 'uuid', 'isSkyPulseReport'], array_keys($members[0]));

        $client->request('GET', '/api/paper_planes/'.$members[0]['uuid'], server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        $plane = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('Mensaje ', $plane['message']);
        self::assertSame('Marta', $plane['authorName']);
        self::assertArrayNotHasKey('code', $plane);

        $client->request('GET', '/planes/'.$members[0]['uuid']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/paper_planes/0190a1b2-0000-7000-8000-000000000000', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->getContainer()->get('doctrine.orm.entity_manager')->getConnection()->rollBack();
    }
}
