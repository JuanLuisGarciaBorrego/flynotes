<?php

declare(strict_types=1);

namespace App\State\Participant;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Participant;
use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use App\Manager\ParticipantManager;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * @implements ProcessorInterface<Participant, Participant>
 */
final readonly class ParticipantRecoveryProcessor implements ProcessorInterface
{
    public function __construct(
        private ParticipantManager $participantManager,
        private CurrentParticipantHelper $currentParticipant,
        private RequestStack $requestStack,
        #[Target('participant_recovery')]
        private RateLimiterFactoryInterface $limiterFactory,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Participant
    {
        // Every attempt counts (also the successful ones), per client IP. Behind a proxy, configure
        // SYMFONY_TRUSTED_PROXIES so the IP is the real client's and not the proxy's.
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->limiterFactory->create($ip)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many attempts. Try again later.');
        }

        // Unknown codes, malformed codes and bot codes are indistinguishable. The code is never logged.
        $participant = $this->participantManager->findByCode((string) $data->code);
        if (null === $participant || ParticipantType::Human !== $participant->getType()) {
            throw new BadRequestHttpException('Invalid recovery code.');
        }

        $this->currentParticipant->login($participant);

        // The caller already knows the code (they just sent it), so returning it reveals nothing new.
        return Participant::fromEntity($participant);
    }
}
