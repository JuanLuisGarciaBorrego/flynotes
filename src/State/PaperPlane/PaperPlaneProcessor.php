<?php

declare(strict_types=1);

namespace App\State\PaperPlane;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PaperPlane as PaperPlaneResource;
use App\Helper\CurrentParticipantHelper;
use App\Manager\PaperPlaneAiManager;

/**
 * POST /paper_planes: publishes the plane and attaches the first AI proposal, pending the author's decision.
 *
 * @implements ProcessorInterface<PaperPlaneResource, PaperPlaneResource>
 */
final readonly class PaperPlaneProcessor implements ProcessorInterface
{
    public function __construct(
        private PaperPlaneAiManager $paperPlaneAiManager,
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaperPlaneResource
    {
        // Access is guaranteed by the operation's `security` expression.
        $participant = $this->currentParticipant->get() ?? throw new \LogicException('No authenticated participant.');

        $paperPlane = $this->paperPlaneAiManager->launch($participant, $data->message);

        // The author's own response: it carries the pending proposal they have to decide on.
        return PaperPlaneResource::fromEntity($paperPlane, forAuthor: true);
    }
}
