<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\PhpProcess;

/**
 * Two real processes deciding the same mission over the HTTP stack. A row lock held here lets both pass
 * validation and queue on the row before they go on, the worst possible interleaving. Their data has to be
 * committed to be visible to them, so tearDown removes it.
 */
class AgentMissionDecisionConcurrencyTest extends KernelTestCase
{
    private const string REQUEST = <<<'PHP'
        <?php
        use App\Kernel;
        use Symfony\Component\Dotenv\Dotenv;
        use Symfony\Component\HttpFoundation\Request;

        require 'vendor/autoload.php';
        (new Dotenv())->bootEnv('.env');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$_SERVER['CODE']];
        $response = (new Kernel('test', true))->handle(Request::create($_SERVER['URI'], 'POST', server: $server, content: $_SERVER['BODY']));
        echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
        PHP;

    private Connection $connection;
    private EntityManagerInterface $em;
    private ?int $missionId = null;
    /** @var list<int> */
    private array $humanIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->executeStatement('DELETE FROM agent_mission WHERE id = ?', [$this->missionId]);
        $this->connection->executeStatement("DELETE FROM paper_plane WHERE created_by_id IN (SELECT id FROM participant WHERE code = 'sky-pulse')");
        $this->connection->executeStatement("DELETE FROM participant WHERE code = 'sky-pulse'");
        foreach ($this->humanIds as $id) {
            $this->connection->executeStatement('DELETE FROM participant WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function decisions(): iterable
    {
        yield 'two approvals' => ['{"accepted": true}', '{"accepted": true}'];
        yield 'approval and rejection' => ['{"accepted": true}', '{"accepted": false}'];
        yield 'two rejections' => ['{"accepted": false}', '{"accepted": false}'];
    }

    #[DataProvider('decisions')]
    public function testOnlyOneSimultaneousDecisionWins(string $first, string $second): void
    {
        $mission = new AgentMission();
        $mission->requestApproval('El misterio', 'Siete aviones vuelan por el cielo.');
        $this->em->persist($mission);
        $this->em->flush();
        $this->missionId = $mission->getId();

        $codes = [$this->humanCode(), $this->humanCode()];

        $this->connection->beginTransaction();
        $this->connection->fetchOne('SELECT id FROM agent_mission WHERE id = ? FOR UPDATE', [$this->missionId]);

        $requests = [$this->request($mission, $codes[0], $first), $this->request($mission, $codes[1], $second)];
        $this->waitUntilBothQueueOnTheRow($requests);
        $this->connection->rollBack();

        $responses = [];
        foreach ($requests as $request) {
            $request->wait();
            $responses[] = json_decode($request->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        }
        usort($responses, static fn (array $a, array $b): int => $a['status'] <=> $b['status']);

        self::assertSame([200, 422], array_column($responses, 'status'), $requests[0]->getErrorOutput().$requests[1]->getErrorOutput());

        $outcome = $responses[0]['body']['status'];
        self::assertSame(
            'completed' === $outcome ? 'This mission has already been approved.' : 'This mission has already been rejected.',
            $responses[1]['body']['detail'],
        );

        $steps = $this->connection->fetchFirstColumn('SELECT type FROM agent_mission_step WHERE mission_id = ? ORDER BY sequence', [$this->missionId]);
        self::assertSame(
            'completed' === $outcome ? ['proposal_created', 'approval_requested', 'approved', 'completed'] : ['proposal_created', 'approval_requested', 'rejected'],
            $steps,
        );
        self::assertSame($outcome, $this->connection->fetchOne('SELECT status FROM agent_mission WHERE id = ?', [$this->missionId]));
        self::assertSame(
            'completed' === $outcome ? 1 : 0,
            (int) $this->connection->fetchOne("SELECT count(*) FROM paper_plane WHERE created_by_id IN (SELECT id FROM participant WHERE code = 'sky-pulse')"),
        );
    }

    private function humanCode(): string
    {
        $human = self::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Humana', 'ES');
        $this->humanIds[] = $human->getId();

        return $human->getCode();
    }

    private function request(AgentMission $mission, string $code, string $body): PhpProcess
    {
        $process = new PhpProcess(self::REQUEST, \dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'CODE' => $code,
            'URI' => sprintf('/api/agent_missions/%s/decision', $mission->getUuid()->toRfc4122()),
            'BODY' => $body,
        ]);
        $process->start();

        return $process;
    }

    /** @param list<PhpProcess> $requests */
    private function waitUntilBothQueueOnTheRow(array $requests): void
    {
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            // pg_locks, not pg_stat_activity: the latter is a snapshot frozen for the whole open transaction.
            $waiting = (int) $this->connection->fetchOne('SELECT count(DISTINCT pid) FROM pg_locks WHERE NOT granted');
            if (2 === $waiting) {
                return;
            }
            usleep(50_000);
        }

        self::fail('The requests did not reach the row lock after passing validation: '.$requests[0]->getOutput().$requests[0]->getErrorOutput().$requests[1]->getOutput().$requests[1]->getErrorOutput());
    }
}
