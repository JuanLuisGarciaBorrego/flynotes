<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Native `access_token` authentication on the `mcp` firewall, backed by McpTokenHandler. The MCP
 * controller is swapped (after the firewall ran) for a probe that reports the authenticated identity,
 * so these tests do not depend on any MCP tool.
 */
class McpTokenHandlerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // A distinct "origin" per test so the persisted rate limiter state never leaks between tests/runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $security = static::getContainer()->get(Security::class);
        static::getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::CONTROLLER,
            static fn (ControllerEvent $event) => $event->setController(static fn () => new JsonResponse([
                'user' => $security->getUser()?->getUserIdentifier(),
                'class' => null === $security->getUser() ? null : $security->getUser()::class,
                'id' => $security->getUser() instanceof Participant ? $security->getUser()->getId() : null,
                'roles' => $security->getUser()?->getRoles() ?? [],
            ])),
        );
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
    private function mcp(?string $authorization): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $this->client->request('POST', '/mcp', server: $server, content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function participant(ParticipantType $type): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, 'Test '.$type->value);
    }

    public function testWithoutBearerTheRequestIsAnonymous(): void
    {
        $data = $this->mcp(null);

        self::assertResponseIsSuccessful();
        self::assertNull($data['user']);
        self::assertResponseNotHasCookie('PHPSESSID');
    }

    public function testNonBearerSchemeIsIgnored(): void
    {
        $data = $this->mcp('Basic dXNlcjpwYXNz');

        self::assertResponseIsSuccessful();
        self::assertNull($data['user']);
    }

    public function testBotIsAuthenticatedWithItsCode(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        $this->em->clear();

        $data = $this->mcp('Bearer '.$bot->getCode());

        self::assertResponseIsSuccessful();
        // The badge only carries the UUID: the `participants` provider reloads the Participant from it.
        self::assertSame($bot->getUserIdentifier(), $data['user']);
        self::assertSame(Participant::class, $data['class']);
        self::assertSame($bot->getId(), $data['id']);
        self::assertSame(['ROLE_MCP'], $data['roles']);
        self::assertResponseNotHasCookie('PHPSESSID');
    }

    public function testCodeIsNormalized(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        $data = $this->mcp('Bearer '.strtolower(str_replace('-', '', $bot->getCode())));

        self::assertSame($bot->getUserIdentifier(), $data['user']);
    }

    /** Symfony's header extractor only recognizes `Bearer <token68>`; anything else is not a Bearer attempt. */
    public function testUnextractableBearerStaysAnonymous(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        foreach (['Bearer', 'Bearer ', 'bearer '.$bot->getCode(), 'Bearer a b'] as $authorization) {
            $data = $this->mcp($authorization);
            self::assertResponseIsSuccessful($authorization);
            self::assertNull($data['user'], $authorization);
        }
    }

    public function testHumanIsNeverAuthenticatedThroughMcp(): void
    {
        $human = $this->participant(ParticipantType::Human);

        $this->mcp('Bearer '.$human->getCode());

        $this->assertGenericUnauthorized($human->getCode());
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->mcp('Bearer AAAA-AAAA-AAAA-AAAA');

        $this->assertGenericUnauthorized('AAAA-AAAA-AAAA-AAAA');
    }

    public function testMalformedCodeIsRejected(): void
    {
        $this->mcp('Bearer garbage');

        $this->assertGenericUnauthorized('garbage');
    }

    public function testBotIsNotRememberedBetweenRequests(): void
    {
        $bot = $this->participant(ParticipantType::Bot);
        $this->mcp('Bearer '.$bot->getCode());

        $data = $this->mcp(null);

        self::assertNull($data['user'], 'The mcp firewall is stateless.');
    }

    /** Unknown code and human code must be indistinguishable, and never echo the credential. */
    private function assertGenericUnauthorized(string $secret): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="invalid_token",error_description="Invalid credentials."');
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->headers);
    }
}
