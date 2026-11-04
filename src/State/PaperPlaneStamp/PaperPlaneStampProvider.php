<?php

declare(strict_types=1);

namespace App\State\PaperPlaneStamp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\PaperPlane;
use App\Repository\PaperPlaneRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the plane to be stamped from the URL. Returning null makes API Platform answer 404
 * (the operation sets `throwOnNotFound`).
 *
 * @implements ProviderInterface<PaperPlane>
 */
final readonly class PaperPlaneStampProvider implements ProviderInterface
{
    public function __construct(
        private PaperPlaneRepository $paperPlaneRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PaperPlane
    {
        // The route requirement guarantees a syntactically valid UUID.
        return $this->paperPlaneRepository->findOneBy(['uuid' => Uuid::fromString($uriVariables['uuid'])]);
    }
}
