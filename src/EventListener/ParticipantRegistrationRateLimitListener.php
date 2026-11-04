<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Limits public participant registrations per client IP. */
final readonly class ParticipantRegistrationRateLimitListener
{
    public function __construct(
        #[Target('participant_registration')]
        private RateLimiterFactoryInterface $limiterFactory,
    ) {
    }

    // After ParticipantBearerListener and ApiCsrfListener: a request they refuse must not consume the client's budget.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod(Request::METHOD_POST) || '/api/participants' !== $request->getPathInfo()) {
            return;
        }

        $limit = $this->limiterFactory->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

            throw new TooManyRequestsHttpException(max(1, $retryAfter), 'Too many registration attempts. Try again later.');
        }
    }
}
