<?php

declare(strict_types=1);

namespace App\State\PaperPlane;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PaperPlane as PaperPlaneResource;
use App\Helper\CurrentParticipantHelper;
use App\Manager\PaperPlaneAiManager;
use App\Manager\PaperPlaneManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /paper_planes/{uuid}/ai-message/retry: replaces the pending AI proposal with a new one. No body.
 * Ownership, state and the generation limit are enforced by PaperPlaneAiManager (403/409 via `exceptionToStatus`).
 *
 * @implements ProcessorInterface<null, PaperPlaneResource>
 */
final readonly class PaperPlaneAiRetryProcessor implements ProcessorInterface
{
    public function __construct(
        private PaperPlaneAiManager $paperPlaneAiManager,
        private PaperPlaneManager $paperPlaneManager,
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaperPlaneResource
    {
        // Access is guaranteed by the operation's `security` expression.
        $participant = $this->currentParticipant->get() ?? throw new \LogicException('No authenticated participant.');

        // The route requirement guarantees a syntactically valid UUID.
        $plane = $this->paperPlaneManager->getByUuid(Uuid::fromString($uriVariables['uuid']))
            ?? throw new NotFoundHttpException('Paper plane not found.');

        $paperPlaneWithNewPropose = $this->paperPlaneAiManager->generateNewPropose($plane, $participant);

        return PaperPlaneResource::fromEntity($paperPlaneWithNewPropose, forAuthor: true);
    }
}
