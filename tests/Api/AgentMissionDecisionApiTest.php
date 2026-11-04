<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\AgentMissionStep;
use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\AgentMissionStatus;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** POST /api/agent_missions/{uuid}/decision. No model may be called here. */
class AgentMissionDecisionApiTest extends WebTestCase
{
    private const string MESSAGE = 'Siete aviones vuelan por el cielo.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $modelCalls = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): string {
            ++$this->modelCalls;

            return 'Never asked.';
        }));
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function human(): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Humana', 'ES');
    }

    private function waitingMission(): AgentMission
    {
        $mission = new AgentMission();
        $mission->requestApproval('El misterio', self::MESSAGE);
        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    /** @return array<string, mixed> */
    private function decide(AgentMission|string $mission, string $body = '{"accepted": true}'): array
    {
        $uuid = $mission instanceof AgentMission ? $mission->getUuid()->toRfc4122() : $mission;
        $this->client->request('POST', sprintf('/api/agent_missions/%s/decision', $uuid), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ], content: $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function planeCount(): int
    {
        return $this->em->getRepository(PaperPlane::class)->count();
    }

    /** @return list<string> */
    private function storedSteps(AgentMission $mission): array
    {
        return array_map(static fn (AgentMissionStep $step): string => $step->getType()->value, $this->em->getRepository(AgentMissionStep::class)->findBy(['mission' => $mission], ['sequence' => 'ASC']));
    }

    public function testApprovingPublishesThePlaneAsSkyPulseAndCompletesTheMission(): void
    {
        $mission = $this->waitingMission();
        $human = $this->human();
        $this->client->loginUser($human, 'main');

        $data = $this->decide($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('completed', $data['status']);
        self::assertFalse($data['requiresApproval']);
        self::assertSame(self::MESSAGE, $data['proposedMessage']);
        self::assertSame(['proposal_created', 'approval_requested', 'approved', 'completed'], array_column($data['steps'], 'type'));
        self::assertSame(0, $this->modelCalls);

        $this->em->clear();
        $plane = $this->em->getRepository(PaperPlane::class)->findOneBy(['uuid' => $data['publishedPlane']]);
        self::assertSame(self::MESSAGE, $plane->getMessage());
        self::assertSame('Sky Pulse', $plane->getCreatedBy()->getName());
        self::assertSame(ParticipantType::Bot, $plane->getCreatedBy()->getType());
        self::assertNotSame($human->getId(), $plane->getCreatedBy()->getId());
    }

    public function testEveryApprovalKeepsTheSameAuthor(): void
    {
        $this->client->loginUser($this->human(), 'main');
        $first = $this->decide($this->waitingMission());
        $second = $this->decide($this->waitingMission());

        $planes = $this->em->getRepository(PaperPlane::class);
        self::assertSame(
            $planes->findOneBy(['uuid' => $first['publishedPlane']])->getCreatedBy()->getId(),
            $planes->findOneBy(['uuid' => $second['publishedPlane']])->getCreatedBy()->getId(),
        );
    }

    public function testRejectingPublishesNothingAndKeepsTheProposal(): void
    {
        $mission = $this->waitingMission();
        $this->client->loginUser($this->human(), 'main');

        $data = $this->decide($mission, '{"accepted": false}');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('rejected', $data['status']);
        self::assertSame(self::MESSAGE, $data['proposedMessage']);
        self::assertArrayNotHasKey('publishedPlane', $data);
        self::assertSame(['proposal_created', 'approval_requested', 'rejected'], array_column($data['steps'], 'type'));
        self::assertSame(0, $this->planeCount());
        self::assertSame(0, $this->modelCalls);
    }

    public function testTheBodyCannotChangeWhatIsPublished(): void
    {
        $mission = $this->waitingMission();
        $this->client->loginUser($this->human(), 'main');

        $data = $this->decide($mission, '{"accepted": true, "message": "Otro mensaje distinto", "mission": null}');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(self::MESSAGE, $this->em->getRepository(PaperPlane::class)->findOneBy(['uuid' => $data['publishedPlane']])->getMessage());
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing' => ['{}', Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'null' => ['{"accepted": null}', Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'not a boolean' => ['{"accepted": "yes"}', Response::HTTP_BAD_REQUEST];
    }

    #[DataProvider('invalidBodies')]
    public function testAcceptedIsARequiredBoolean(string $body, int $status): void
    {
        $mission = $this->waitingMission();
        $this->client->loginUser($this->human(), 'main');

        $this->decide($mission, $body);

        self::assertResponseStatusCodeSame($status);
        self::assertSame(AgentMissionStatus::WaitingApproval, $mission->getStatus());
        self::assertSame(0, $this->planeCount());
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $this->decide($this->waitingMission());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(0, $this->planeCount());
    }

    public function testBotSessionIsForbidden(): void
    {
        $mission = $this->waitingMission();
        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot'), 'main');

        $this->decide($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->planeCount());
    }

    /** The web firewall does not accept bot codes: rejected before reaching the operation. */
    public function testBotBearerIsUnauthorized(): void
    {
        $mission = $this->waitingMission();
        // A distinct origin, so the persisted Bearer failure limiter never leaks between runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot')->getCode());

        $this->decide($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(0, $this->planeCount());
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function secondDecisions(): iterable
    {
        yield 'approve an approved one' => [true, '{"accepted": true}', 'This mission has already been approved.'];
        yield 'reject an approved one' => [true, '{"accepted": false}', 'This mission has already been approved.'];
        yield 'approve a rejected one' => [false, '{"accepted": true}', 'This mission has already been rejected.'];
        yield 'reject a rejected one' => [false, '{"accepted": false}', 'This mission has already been rejected.'];
    }

    #[DataProvider('secondDecisions')]
    public function testASecondDecisionChangesNothing(bool $approveFirst, string $body, string $message): void
    {
        $mission = $this->waitingMission();
        $this->client->loginUser($this->human(), 'main');
        $this->decide($mission, $approveFirst ? '{"accepted": true}' : '{"accepted": false}');
        $steps = $this->storedSteps($mission);
        $status = $mission->getStatus();

        $data = $this->decide($mission, $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($message, $data['violations'][0]['message']);
        self::assertSame($status, $mission->getStatus());
        self::assertSame($steps, $this->storedSteps($mission));
        self::assertSame($approveFirst ? 1 : 0, $this->planeCount());
    }

    /** @return iterable<string, array{AgentMissionStatus, string}> */
    public static function notDecidable(): iterable
    {
        yield 'running' => [AgentMissionStatus::Running, 'This mission is not ready for approval yet.'];
        yield 'waiting for its tool' => [AgentMissionStatus::WaitingToolApproval, 'This mission is not ready for approval yet.'];
        yield 'failed' => [AgentMissionStatus::Failed, 'This mission is no longer available for approval.'];
    }

    #[DataProvider('notDecidable')]
    public function testAMissionThatNeverAskedForApprovalCannotBeDecided(AgentMissionStatus $status, string $message): void
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, $status);
        $this->em->persist($mission);
        $this->em->flush();
        $this->client->loginUser($this->human(), 'main');

        $data = $this->decide($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($message, $data['violations'][0]['message']);
        self::assertSame(0, $this->planeCount());
    }

    public function testAnUnknownMissionIsNotFound(): void
    {
        $this->client->loginUser($this->human(), 'main');

        $this->decide('0198f1a2-7b3c-7d4e-8f50-123456789abc');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
