<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Authenticator\AccessTokenAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Limits failed Bearer attempts per client IP, with one budget shared by the `main` and `mcp` firewalls.
 * The key is only the IP (behind a proxy, configure SYMFONY_TRUSTED_PROXIES): never the code.
 */
final readonly class BearerRateLimitListener
{
    public function __construct(
        #[Target('bearer_authentication_failure')]
        private RateLimiterFactoryInterface $limiterFactory,
    ) {
    }

    // Before the firewall (8): once the budget is spent, no code is looked up and the session is not read.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !ParticipantBearerListener::carriesBearer($request)) {
            return;
        }

        // consume(0) reads the budget without spending it: an accepted Bearer costs nothing.
        $limit = $this->limiter($request)->consume(0);
        if (0 === $limit->getRemainingTokens()) {
            $event->setResponse(new Response(null, Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]));
        }
    }

    // Bubbles up from the dispatcher of each firewall.
    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getAuthenticator() instanceof AccessTokenAuthenticator) {
            $this->recordFailure($event->getRequest());
        }
    }

    public function recordFailure(Request $request): void
    {
        $this->limiter($request)->consume();
    }

    private function limiter(Request $request): LimiterInterface
    {
        return $this->limiterFactory->create($request->getClientIp() ?? 'unknown');
    }
}
