<?php

declare(strict_types=1);

namespace App\Tests\Manager;

use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use App\Helper\ParticipantCodeGeneratorHelper;
use App\Manager\ParticipantManager;
use App\Repository\ParticipantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;

class ParticipantManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParticipantManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->manager = new ParticipantManager($c->get(ParticipantRepository::class), $this->em, new ParticipantCodeGeneratorHelper());
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testCreateHuman(): void
    {
        $p = $this->manager->create(ParticipantType::Human, '  Ana   María ', 'es');

        self::assertNotNull($p->getId());
        self::assertSame(ParticipantType::Human, $p->getType());
        self::assertSame('Ana María', $p->getName());
        self::assertSame('ES', $p->getCountryCode());
        self::assertNotNull($p->getPersonality(), 'The emoji is one of EmojiPersonality.');
        self::assertSame($p->getPersonality()->value, $p->getEmoji());
        self::assertSame(19, strlen($p->getCode()));
    }

    public function testCreateBotWithoutCountry(): void
    {
        $p = $this->manager->create(ParticipantType::Bot, 'Robo');

        self::assertSame(ParticipantType::Bot, $p->getType());
        self::assertNull($p->getCountryCode());
    }

    public function testCodesAreUnique(): void
    {
        $a = $this->manager->create(ParticipantType::Human, 'A');
        $b = $this->manager->create(ParticipantType::Human, 'B');

        self::assertNotSame($a->getCode(), $b->getCode());
    }

    public function testFindByCode(): void
    {
        $p = $this->manager->create(ParticipantType::Human, 'Ana');
        $this->em->clear();

        $code = $p->getCode();
        self::assertSame($p->getId(), $this->manager->findByCode($code)?->getId());
        self::assertSame($p->getId(), $this->manager->findByCode(' '.strtolower(str_replace('-', '', $code)).' ')?->getId());
        self::assertNull($this->manager->findByCode('AAAA-AAAA-AAAA-AAAA'));
        self::assertNull($this->manager->findByCode('garbage'));
        self::assertCount(1, self::getContainer()->get(ParticipantRepository::class)->findBy(['name' => 'Ana']));
    }

    public function testOnlyHumansCanBeLoggedInOnTheWeb(): void
    {
        $current = new CurrentParticipantHelper(self::getContainer()->get(Security::class));

        $this->expectException(\InvalidArgumentException::class);
        $current->login($this->manager->create(ParticipantType::Bot, 'Robo'));
    }
}
