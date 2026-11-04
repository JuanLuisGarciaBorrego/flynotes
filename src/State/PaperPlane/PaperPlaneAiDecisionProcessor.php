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
 * POST /paper_planes/{uuid}/ai-message/decision `{"accepted": bool, "versionId": uuid}`: final decision; accepting
 * approves the version the author saw (versionId), rejecting discards them all.
 * Ownership and state are enforced by PaperPlaneAiManager (403/409 via `exceptionToStatus`).
 *
 * @implements ProcessorInterface<PaperPlaneResource, PaperPlaneResource>
 */
final readonly class PaperPlaneAiDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private PaperPlaneAiManager $paperPlaneAiManager,
        private PaperPlaneManager $paperPlaneManager,
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    /** @param PaperPlaneResource $data validated input (`accepted` is not null) */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaperPlaneResource
    {
        // Access is guaranteed by the operation's `security` expression.
        $participant = $this->currentParticipant->get() ?? throw new \LogicException('No authenticated participant.');

        // The route requirement guarantees a syntactically valid UUID.
        $plane = $this->paperPlaneManager->getByUuid(Uuid::fromString($uriVariables['uuid']))
            ?? throw new NotFoundHttpException('Paper plane not found.');

        // Validation guarantees a well-formed versionId whenever accepted is true.
        $versionId = $data->accepted ? Uuid::fromString((string) $data->versionId) : null;
        $plane = $this->paperPlaneAiManager->decide($plane, $participant, (bool) $data->accepted, $versionId);

        return PaperPlaneResource::fromEntity($plane, forAuthor: true);
    }
}
