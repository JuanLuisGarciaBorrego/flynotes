<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PaperPlane;
use App\Enum\AiMessageStatus;
use App\Exception\AiMessageStateException;
use PHPUnit\Framework\TestCase;

/** The AI reinterpretation state machine of the entity, without database. */
class PaperPlaneTest extends TestCase
{
    public function testAPlaneWithoutAiHasNoVersionsAndAllGenerationsLeft(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');

        self::assertNull($plane->getAiMessage());
        self::assertNull($plane->getAiStatus());
        self::assertNull($plane->getCurrentAiVersion());
        self::assertSame([], $plane->getAiVersions());
        self::assertSame(0, $plane->getAiAttempts());
        self::assertSame(PaperPlane::AI_MAX_ATTEMPTS, $plane->getAiAttemptsRemaining());
    }

    public function testEveryGenerationIsKeptUpToTheLimit(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');

        foreach (['Primera', 'Segunda', 'Tercera'] as $i => $text) {
            $version = $plane->proposeAiMessage($text, 'Tono '.$i);
            self::assertSame($i + 1, $version->getNumber());
            self::assertSame('Tono '.$i, $version->getTone());
            self::assertSame($version, $plane->getCurrentAiVersion());
            self::assertSame($i + 1, $plane->getAiAttempts());
            self::assertSame(AiMessageStatus::Pending, $plane->getAiStatus());
            // Nothing is published while pending.
            self::assertNull($plane->getAiMessage());
        }
        self::assertSame(['Primera', 'Segunda', 'Tercera'], array_map(static fn ($v): string => $v->getText(), $plane->getAiVersions()));
        self::assertSame(0, $plane->getAiAttemptsRemaining());

        try {
            $plane->proposeAiMessage('Cuarta');
            self::fail('A fourth generation must be refused.');
        } catch (AiMessageStateException) {
        }
        self::assertCount(PaperPlane::AI_MAX_ATTEMPTS, $plane->getAiVersions());
        self::assertSame(PaperPlane::AI_MAX_ATTEMPTS, $plane->getAiAttempts());
        self::assertSame('Mensaje original', $plane->getMessage());
    }

    /** The latest version is the one on screen, and all of them stay as history. */
    public function testAcceptPublishesTheLatestVersion(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');
        $plane->proposeAiMessage('Primera');
        $latest = $plane->proposeAiMessage('Segunda');

        $plane->acceptAiVersion($latest);

        self::assertSame(AiMessageStatus::Accepted, $plane->getAiStatus());
        self::assertSame($latest, $plane->getApprovedAiVersion());
        self::assertSame('Segunda', $plane->getAiMessage());
        self::assertCount(2, $plane->getAiVersions());
        self::assertSame('Mensaje original', $plane->getMessage());
    }

    /** A new version replaces the previous one: approving it means the author was looking at an old screen. */
    public function testAReplacedVersionCannotBeApproved(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');
        $first = $plane->proposeAiMessage('Primera');
        $plane->proposeAiMessage('Segunda');

        try {
            $plane->acceptAiVersion($first);
            self::fail('A replaced version must not be approved.');
        } catch (AiMessageStateException) {
        }

        self::assertSame(AiMessageStatus::Pending, $plane->getAiStatus());
        self::assertNull($plane->getApprovedAiVersion());
    }

    public function testAVersionOfAnotherPlaneCannotBeApproved(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');
        $plane->proposeAiMessage('Propia');
        $foreign = new PaperPlane()->setMessage('Otro avión')->proposeAiMessage('Ajena');

        $this->expectException(AiMessageStateException::class);
        $plane->acceptAiVersion($foreign);
    }

    public function testRejectPublishesNothingButKeepsTheHistory(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');
        $plane->proposeAiMessage('Primera');
        $plane->proposeAiMessage('Segunda');
        $plane->rejectAiMessage();

        self::assertSame(AiMessageStatus::Rejected, $plane->getAiStatus());
        self::assertNull($plane->getAiMessage());
        self::assertNull($plane->getApprovedAiVersion());
        self::assertCount(2, $plane->getAiVersions());
        self::assertSame('Mensaje original', $plane->getMessage());
    }

    /** @return iterable<string, array{callable(PaperPlane): mixed}> */
    public static function forbiddenAfterADecision(): iterable
    {
        yield 'propose' => [static fn (PaperPlane $plane) => $plane->proposeAiMessage('Otra')];
        yield 'accept' => [static fn (PaperPlane $plane) => $plane->acceptAiVersion($plane->getAiVersions()[0])];
        yield 'reject' => [static fn (PaperPlane $plane) => $plane->rejectAiMessage()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenAfterADecision')]
    public function testDecisionsAreFinal(callable $change): void
    {
        foreach ([true, false] as $accepted) {
            $plane = new PaperPlane()->setMessage('Mensaje original');
            $version = $plane->proposeAiMessage('Versión IA');
            $accepted ? $plane->acceptAiVersion($version) : $plane->rejectAiMessage();
            $status = $plane->getAiStatus();
            $aiMessage = $plane->getAiMessage();

            try {
                $change($plane);
                self::fail('A decided reinterpretation must not change.');
            } catch (AiMessageStateException) {
            }
            self::assertSame($status, $plane->getAiStatus());
            self::assertSame($aiMessage, $plane->getAiMessage());
            self::assertSame(1, $plane->getAiAttempts());
        }
    }

    public function testNothingToDecideWithoutAProposal(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');

        $this->expectException(AiMessageStateException::class);
        $plane->rejectAiMessage();
    }

    public function testTheOriginalMessageIsImmutable(): void
    {
        $plane = new PaperPlane()->setMessage('Mensaje original');

        $this->expectException(\LogicException::class);
        $plane->setMessage('Otro mensaje');
    }
}
