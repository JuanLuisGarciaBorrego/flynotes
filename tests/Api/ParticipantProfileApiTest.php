<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/participants/me: the profile of the human authenticated by the web session. */
class ParticipantProfileApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the seeded transaction visible across requests
        // A distinct "origin" per test so the persisted recovery rate limiter never leaks between tests/runs.
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

    private function participant(ParticipantType $type, string $name, ?string $countryCode = null): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, $name, $countryCode);
    }

    /**
     * @param array<string, string> $extraServer
     *
     * @return array<string, mixed>
     */
    private function profile(string $uri = '/api/participants/me', array $extraServer = []): array
    {
        $this->client->request('GET', $uri, server: $extraServer + ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function post(string $uri, array $body): array
    {
        $this->client->request('POST', $uri, server: ParticipantApiTest::jsonLd(), content: json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testHumanGetsOwnProfileWithoutCode(): void
    {
        $human = $this->participant(ParticipantType::Human, 'Marta', 'ES');
        $this->client->loginUser($human, 'main');

        $data = $this->profile();

        self::assertResponseIsSuccessful();
        self::assertSame([
            'uuid' => $human->getUuid()->toRfc4122(),
            'name' => 'Marta',
            'emoji' => $human->getEmoji(),
            'countryCode' => 'ES',
        ], $data);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString($human->getCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testJsonLdNeverIncludesCode(): void
    {
        $human = $this->participant(ParticipantType::Human, 'Marta', 'ES');
        $this->client->loginUser($human, 'main');

        $data = $this->profile(extraServer: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
        self::assertSame(['@context', '@id', '@type', 'uuid', 'name', 'emoji', 'countryCode'], array_keys($data));
        self::assertStringNotContainsString($human->getCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $this->profile();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testBotBearerIsNotAnIdentityHere(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'Bot', 'ES');

        // The /api firewall only reads the web session: the MCP Bearer leaves the request anonymous.
        $this->profile(extraServer: ['HTTP_AUTHORIZATION' => 'Bearer '.$bot->getCode()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringNotContainsString($bot->getCode(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($bot->getUuid()->toRfc4122(), (string) $this->client->getResponse()->getContent());
    }

    public function testBotSessionIsForbidden(): void
    {
        $this->client->loginUser($this->participant(ParticipantType::Bot, 'Bot'), 'main');

        $this->profile();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnotherParticipantCannotBeSelected(): void
    {
        $me = $this->participant(ParticipantType::Human, 'Yo', 'ES');
        $other = $this->participant(ParticipantType::Human, 'Otra', 'PT');
        $otherUuid = $other->getUuid()->toRfc4122();
        $this->client->loginUser($me, 'main');

        // Query parameters are ignored: the identity always comes from the session.
        $data = $this->profile('/api/participants/me?uuid='.$otherUuid.'&code='.urlencode($other->getCode()).'&id='.$other->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($me->getUuid()->toRfc4122(), $data['uuid']);
        self::assertStringNotContainsString($otherUuid, (string) $this->client->getResponse()->getContent());

        // There is no item operation by identifier.
        $this->profile('/api/participants/'.$otherUuid);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRegistrationAndRecoveryStillReturnCodeAndOpenTheSession(): void
    {
        $registered = $this->post('/api/participants', ['name' => 'Marta', 'countryCode' => 'ES']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(['@context', '@id', '@type', 'uuid', 'name', 'emoji', 'countryCode', 'code'], array_keys($registered));
        self::assertSame('/api/participants/'.$registered['uuid'], $registered['@id']);

        $profile = $this->profile();
        self::assertResponseIsSuccessful();
        self::assertSame(array_diff_key($registered, array_flip(['@context', '@id', '@type', 'code'])), $profile);

        // A new browser: no session until the code is exchanged.
        $this->client->getCookieJar()->clear();
        $this->profile();
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $recovered = $this->post('/api/participants/recover', ['code' => $registered['code']]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($registered['code'], $recovered['code']);
        self::assertSame($registered['uuid'], $recovered['uuid']);

        self::assertSame($profile, $this->profile());
        self::assertResponseIsSuccessful();
    }
}
