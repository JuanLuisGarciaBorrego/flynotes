<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Enum\AgentMissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/agent_missions/current: how a visitor finds the mission without knowing its UUID. */
class AgentMissionCurrentApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function store(AgentMissionStatus $status): AgentMission
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, $status);
        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    /** @return array<string, mixed> */
    private function current(string $accept = 'application/json'): array
    {
        $this->em->clear();
        $this->client->request('GET', '/api/agent_missions/current', server: ['HTTP_ACCEPT' => $accept]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testNoMissionsIsANotFound(): void
    {
        $this->current();

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testFinishedMissionsGiveTheLastOneToAnAnonymousVisitor(): void
    {
        $this->store(AgentMissionStatus::Completed);
        $last = $this->store(AgentMissionStatus::Rejected);

        $data = $this->current();

        self::assertResponseIsSuccessful();
        self::assertSame($last->getUuid()->toRfc4122(), $data['uuid']);
        self::assertSame('rejected', $data['status']);
    }

    /** @return iterable<string, array{AgentMissionStatus}> */
    public static function activeStatuses(): iterable
    {
        yield 'running' => [AgentMissionStatus::Running];
        yield 'waiting for its tool' => [AgentMissionStatus::WaitingToolApproval];
        yield 'waiting for its publication' => [AgentMissionStatus::WaitingApproval];
    }

    /** Two simultaneous starts can leave it so: what blocks starting another is what must be shown. */
    #[DataProvider('activeStatuses')]
    public function testAnActiveMissionWinsOverANewerFinishedOne(AgentMissionStatus $status): void
    {
        $active = $this->store($status);
        $this->store(AgentMissionStatus::Failed);

        self::assertSame($active->getUuid()->toRfc4122(), $this->current()['uuid']);
    }

    /** Same shape as GET /agent_missions/{uuid}, also in the JSON-LD the page asks for, private context excluded. */
    public function testSameRepresentationAsTheMissionItself(): void
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_secret', 'get_sky_emojis', [], ['messages' => ['secret context']]);
        $this->em->persist($mission);
        $this->em->flush();
        $uuid = $mission->getUuid()->toRfc4122();

        $current = $this->current('application/ld+json');
        self::assertResponseIsSuccessful();
        self::assertSame('/api/agent_missions/'.$uuid, $current['@id']);
        self::assertSame(['name' => 'get_sky_emojis', 'arguments' => []], $current['pendingTool']);

        $this->client->request('GET', '/api/agent_missions/'.$uuid, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertSame(json_decode((string) $this->client->getResponse()->getContent(), true), $current);
        self::assertStringNotContainsString('secret', (string) $this->client->getResponse()->getContent());
    }

    public function testItIsReadOnly(): void
    {
        $this->client->request('POST', '/api/agent_missions/current', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
