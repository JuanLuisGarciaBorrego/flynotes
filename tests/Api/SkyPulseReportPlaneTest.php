<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** `isSkyPulseReport`, what paints a plane yellow: the reserved identity decides, never the displayed name. */
class SkyPulseReportPlaneTest extends WebTestCase
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

    private function plane(Participant $author): PaperPlane
    {
        return static::getContainer()->get(PaperPlaneManager::class)->create($author, 'A message long enough to fly.');
    }

    private function participants(): ParticipantManager
    {
        return static::getContainer()->get(ParticipantManager::class);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testOnlyTheReservedIdentityMakesAReport(): void
    {
        $skyPulse = $this->participants()->getSkyPulse();
        $expected = [
            $this->plane($skyPulse)->getUuid()->toRfc4122() => true,
            $this->plane($this->participants()->create(ParticipantType::Human, 'Humana', 'ES'))->getUuid()->toRfc4122() => false,
            // Anyone can register this name: it must not be able to pass for the agent.
            $this->plane($this->participants()->create(ParticipantType::Bot, 'Sky Pulse', 'ES'))->getUuid()->toRfc4122() => false,
        ];

        foreach ($expected as $uuid => $isReport) {
            self::assertSame($isReport, $this->get('/api/paper_planes/'.$uuid)['isSkyPulseReport'], $uuid);
        }

        $listed = array_column($this->get('/api/paper_planes'), 'isSkyPulseReport', 'uuid');
        self::assertEqualsCanonicalizing($expected, array_intersect_key($listed, $expected));
        foreach ($expected as $uuid => $isReport) {
            self::assertSame($isReport, $listed[$uuid], $uuid);
        }
    }

    /** It survives a rename, because it was never about the name. */
    public function testARenamedAgentStillPublishesReports(): void
    {
        $skyPulse = $this->participants()->getSkyPulse()->setName('Renamed');
        $plane = $this->plane($skyPulse);

        self::assertTrue($this->get('/api/paper_planes/'.$plane->getUuid()->toRfc4122())['isSkyPulseReport']);
    }

    /** The robot is only how the modal paints a report: the agent keeps its emoji, stored and in the API. */
    public function testAReportKeepsTheAgentsStoredEmoji(): void
    {
        $skyPulse = $this->participants()->getSkyPulse();
        $emoji = $skyPulse->getEmoji();
        $plane = $this->plane($skyPulse);

        self::assertNotNull($emoji);
        self::assertSame($emoji, $this->get('/api/paper_planes/'.$plane->getUuid()->toRfc4122())['authorEmoji']);
        self::assertSame($emoji, $skyPulse->getEmoji());
    }

    /** A report has no AI version, so the emoji in the label of that block can never appear on one. */
    public function testAReportHasNoAiVersionToLabel(): void
    {
        $plane = $this->plane($this->participants()->getSkyPulse());

        self::assertNull($plane->getAiStatus());
    }

    public function testTheReservedCodeNeverLeavesTheApi(): void
    {
        $plane = $this->plane($this->participants()->getSkyPulse());

        foreach (['/api/paper_planes', '/api/paper_planes/'.$plane->getUuid()->toRfc4122()] as $path) {
            $this->get($path);
            self::assertStringNotContainsString(Participant::SKY_PULSE_CODE, (string) $this->client->getResponse()->getContent());
        }
    }
}
