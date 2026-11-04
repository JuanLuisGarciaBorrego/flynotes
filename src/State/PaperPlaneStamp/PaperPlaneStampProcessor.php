<?php

declare(strict_types=1);

namespace App\State\PaperPlaneStamp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PaperPlaneStamp;
use App\Entity\PaperPlane;
use App\Entity\PlaneVisit;
use App\Helper\CurrentParticipantHelper;
use App\Helper\StampColorHelper;
use App\Manager\PlaneVisitManager;
use Symfony\Component\Intl\Countries;

/**
 * @implements ProcessorInterface<PaperPlane, PaperPlaneStamp>
 */
final readonly class PaperPlaneStampProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaneVisitManager $planeVisitManager,
        private CurrentParticipantHelper $currentParticipant,
        private StampColorHelper $stampColor,
    ) {
    }

    /**
     * @param PaperPlane $data loaded by PaperPlaneStampProvider (404 if missing)
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaperPlaneStamp
    {
        // Access is guaranteed by the operation's `security` expression.
        $participant = $this->currentParticipant->get() ?? throw new \LogicException('No authenticated participant.');

        // Domain exceptions are mapped to 403/409 by the operation's `exceptionToStatus`.
        $stamp = $this->planeVisitManager->registerParticipation($data, $participant);

        return $this->toResource($stamp, $data);
    }

    private function toResource(PlaneVisit $stamp, PaperPlane $plane): PaperPlaneStamp
    {
        $countryCode = $stamp->getCountryCode();

        $output = new PaperPlaneStamp();
        $output->uuid = $plane->getUuid()->toRfc4122();
        $output->stampUuid = $stamp->getUuid()->toRfc4122();
        $output->color = $this->stampColor->colorFor($stamp->getUuid());
        $output->countryCode = $countryCode;
        $output->countryName = null === $countryCode ? null : Countries::getName($countryCode, 'en');
        $output->emoji = $stamp->getEmoji();
        $output->createdAt = $stamp->getCreatedAt()?->format(\DATE_ATOM);

        return $output;
    }
}
