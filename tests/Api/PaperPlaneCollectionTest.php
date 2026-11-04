<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class PaperPlaneCollectionTest extends WebTestCase
{
    private function seed(KernelBrowser $client, int $planes): void
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $em->getConnection()->beginTransaction();

        $author = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Test');
        $planeManager = static::getContainer()->get(PaperPlaneManager::class);
        for ($i = 0; $i < $planes; ++$i) {
            $planeManager->create($author, 'Mensaje '.$i);
        }
    }

    /** @return list<string> */
    private function uuids(KernelBrowser $client, string $query = ''): array
    {
        $client->request('GET', '/api/paper_planes'.$query, server: ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return array_column($data['member'] ?? $data, 'uuid');
    }

    protected function tearDown(): void
    {
        if (static::getContainer()->has('doctrine.orm.entity_manager')) {
            $connection = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        parent::tearDown();
    }

    public function testReturnsThirtyWithoutDuplicates(): void
    {
        $client = static::createClient();
        $this->seed($client, 35);

        $uuids = $this->uuids($client);

        self::assertResponseIsSuccessful();
        self::assertCount(30, $uuids);
        self::assertCount(30, array_unique($uuids));
    }

    public function testLimitIsNotClientConfigurable(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep the seeded transaction visible across requests
        $this->seed($client, 35);

        self::assertCount(30, $this->uuids($client, '?limit=5'));
        self::assertCount(30, $this->uuids($client, '?limit=100'));
    }

    public function testCollectionOnlyExposesUuidsAndItemRevealsMessage(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep the seeded transaction visible across requests
        $this->seed($client, 3);

        $client->request('GET', '/api/paper_planes', server: ['HTTP_ACCEPT' => 'application/json']);
        $first = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)[0];
        self::assertSame(['uuid', 'isSkyPulseReport'], array_keys($first));

        $client->request('GET', '/api/paper_planes/'.$first['uuid'], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $plane = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('Mensaje ', $plane['message']);
        self::assertSame('Test', $plane['authorName']);
        self::assertArrayNotHasKey('code', $plane);

        $client->request('GET', '/planes/'.$first['uuid']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOrderVariesBetweenRequests(): void
    {
        $client = static::createClient();
        $this->seed($client, 12);

        $orders = [];
        for ($i = 0; $i < 10; ++$i) {
            $orders[] = implode(',', $this->uuids($client, ''));
        }

        // 12! orderings: 10 identical results in a row would be astronomically unlikely.
        self::assertGreaterThan(1, \count(array_unique($orders)));
    }
}
