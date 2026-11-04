<?php

declare(strict_types=1);

namespace App\State\PaperPlane;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PaperPlane as PaperPlaneResource;
use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Helper\CurrentParticipantHelper;
use App\Helper\StampColorHelper;
use App\Manager\PaperPlaneManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Uid\Uuid;

/**
 * HTTP adapter of PaperPlaneManager: maps its results to the ApiResource. The use cases (random feed, visit
 * recording, viewer flags) live in the manager so the MCP tools can share them.
 *
 * @implements ProviderInterface<PaperPlaneResource>
 */
final readonly class PaperPlaneProvider implements ProviderInterface
{
    public function __construct(
        private PaperPlaneManager $paperPlaneManager,
        private CurrentParticipantHelper $currentParticipant,
        private StampColorHelper $stampColor,
    ) {
    }

    /** @return list<PaperPlaneResource>|PaperPlaneResource|null */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|PaperPlaneResource|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map($this->toResource(...), $this->paperPlaneManager->getRandom());
        }

        $request = $context['request'] ?? null;
        $participant = $this->currentParticipant->get();

        // The route requirement guarantees a syntactically valid UUID.
        // Ignore HEAD and browser prefetch requests to avoid counting technical requests as plane visits.
        // This does not prevent all duplicate views: normal repeated reads are intentionally counted.
        $detail = $this->paperPlaneManager->show(
            Uuid::fromString($uriVariables['uuid']),
            $participant,
            recordView: !($request instanceof Request && $this->isTechnicalRequest($request)),
        );

        return null === $detail ? null : $this->toDetailResource($detail, $participant);
    }

    private function isTechnicalRequest(Request $request): bool
    {
        if ($request->isMethod('HEAD')) {
            return true;
        }

        foreach (['Sec-Purpose', 'Purpose'] as $header) {
            if (1 === preg_match('/(?:^|[\s;,])prefetch(?:$|[\s;,])/i', (string) $request->headers->get($header))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{plane: PaperPlane, viewCount: int, stamps: list<PlaneVisit>, isAuthor: bool, hasStamped: bool, canStamp: bool} $detail
     */
    private function toDetailResource(array $detail, ?Participant $participant): PaperPlaneResource
    {
        $plane = $detail['plane'];
        $resource = $this->toResource($plane, $detail['isAuthor']);
        $resource->authorCountryCode = $plane->getCreatedBy()?->getCountryCode();
        $resource->createdAt = $plane->getCreatedAt()?->format(\DATE_ATOM);
        $resource->viewCount = $detail['viewCount'];

        foreach ($detail['stamps'] as $stamp) {
            $resource->stamps[] = [
                'uuid' => $stamp->getUuid()->toRfc4122(),
                'color' => $this->stampColor->colorFor($stamp->getUuid()),
                'countryCode' => $stamp->getCountryCode(),
                'countryName' => $this->countryName($stamp->getCountryCode()),
                'emoji' => $stamp->getEmoji(),
                'mine' => null !== $participant && $stamp->getParticipant()?->getId() === $participant->getId(),
                'createdAt' => $stamp->getCreatedAt()?->format(\DATE_ATOM),
            ];
        }

        if (null === $participant) {
            return $resource;
        }

        $resource->isAuthenticated = true;
        $resource->isAuthor = $detail['isAuthor'];
        $resource->hasStamped = $detail['hasStamped'];
        $resource->stampedByMe = $detail['hasStamped'];
        $resource->canStamp = $detail['canStamp'];
        $resource->viewerEmoji = $participant->getEmoji();
        $resource->viewerCountryCode = $participant->getCountryCode();
        $resource->viewerCountryName = $this->countryName($participant->getCountryCode());

        return $resource;
    }

    private function countryName(?string $countryCode): ?string
    {
        return null !== $countryCode && Countries::exists($countryCode) ? Countries::getName($countryCode, 'en') : null;
    }

    /** Public read: the pending AI proposal is only shown to the author (see PaperPlaneResource::fromEntity()). */
    private function toResource(PaperPlane $plane, bool $forAuthor = false): PaperPlaneResource
    {
        return PaperPlaneResource::fromEntity($plane, $forAuthor);
    }
}
