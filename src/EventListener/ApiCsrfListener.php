<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF protection for state-changing API requests sent by the browser with the session cookie.
 *
 * Uses Symfony's stateless (same-origin) CSRF manager: the request must come from the same origin
 * (Sec-Fetch-Site / Origin / Referer) and/or carry the `csrf-token` header, which a cross-site page
 * cannot add without a CORS preflight (no CORS is configured).
 *
 * Runs after ParticipantBearerListener (7): only a Bearer that actually authenticated is exempt, never
 * the mere presence of the header.
 */
final readonly class ApiCsrfListener
{
    public const string TOKEN_ID = 'api';
    public const string HEADER = 'csrf-token';

    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if (ParticipantBearerListener::isAuthenticated($request)) {
            return;
        }

        $token = new CsrfToken(self::TOKEN_ID, (string) $request->headers->get(self::HEADER, ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
