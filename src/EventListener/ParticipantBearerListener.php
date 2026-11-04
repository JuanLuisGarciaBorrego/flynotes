<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\AccessTokenAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Makes `Authorization: Bearer` a per-request credential on `main`, which is a stateful firewall:
 * - knows whether the Bearer authenticator actually succeeded (never inferred from the header);
 * - evaluates every Bearer, also where the lazy firewall would not, so a wrong one cannot fall back to the session;
 * - hands the session its own identity back before it is persisted, so a Bearer never reaches it.
 *
 * Nothing is kept in properties: the state lives in the request (FrankenPHP worker).
 */
final readonly class ParticipantBearerListener
{
    private const string FIREWALL = 'main';
    /** Set only by a successful Bearer: the identity it displaced, which is null without a session. */
    private const string DISPLACED_TOKEN = '_participant_bearer_displaced_token';

    public function __construct(
        private Security $security,
        private TokenStorageInterface $tokenStorage,
        private BearerRateLimitListener $rateLimit,
    ) {
    }

    public static function isAuthenticated(Request $request): bool
    {
        return $request->attributes->has(self::DISPLACED_TOKEN);
    }

    public static function carriesBearer(Request $request): bool
    {
        return 1 === preg_match('/^bearer(?:\s|$)/i', (string) $request->headers->get('Authorization', ''));
    }

    // Before SessionStrategyListener (0): BearerAwareSessionStrategy relies on isAuthenticated().
    #[AsEventListener(event: LoginSuccessEvent::class, priority: 1, dispatcher: 'security.event_dispatcher.main')]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticator() instanceof AccessTokenAuthenticator) {
            $event->getRequest()->attributes->set(self::DISPLACED_TOKEN, $event->getPreviousToken());
        }
    }

    // Right after the firewall (8), before ApiCsrfListener and the rate limiter.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::carriesBearer($request) || self::FIREWALL !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }

        // Runs the lazy firewall: a rejected Bearer ends the request here with its 401.
        $this->tokenStorage->getToken();

        // Symfony's extractor ignores what is not `Bearer <token68>`: same 401, no session fallback.
        if (!self::isAuthenticated($request)) {
            // No authenticator ran, so there is no LoginFailureEvent to count it.
            $this->rateLimit->recordFailure($request);
            $event->setResponse(new Response(null, Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Bearer error="invalid_token",error_description="Invalid credentials."',
            ]));
        }
    }

    // Before ContextListener (0), which persists whatever identity it finds in the token storage.
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 1)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && self::isAuthenticated($request)) {
            $this->tokenStorage->setToken($request->attributes->get(self::DISPLACED_TOKEN));
        }
    }
}
