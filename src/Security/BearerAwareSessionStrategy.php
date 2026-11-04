<?php

declare(strict_types=1);

namespace App\Security;

use App\EventListener\ParticipantBearerListener;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategyInterface;

/** Session fixation protection stays for real logins; a Bearer request leaves the session in its cookie untouched. */
#[AsDecorator('security.authentication.session_strategy')]
final readonly class BearerAwareSessionStrategy implements SessionAuthenticationStrategyInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SessionAuthenticationStrategyInterface $inner,
    ) {
    }

    public function onAuthentication(Request $request, TokenInterface $token): void
    {
        if (!ParticipantBearerListener::isAuthenticated($request)) {
            $this->inner->onAuthentication($request, $token);
        }
    }
}
