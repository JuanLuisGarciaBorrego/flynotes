<?php

declare(strict_types=1);

namespace App\Helper;

use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Security\ParticipantSessionAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Bridge between the HTTP layer and Symfony Security. get() reads the participant authenticated on the
 * current request, whatever the firewall: a human from the web session or their Bearer (Twig, API
 * Platform) or a bot from its Bearer (MCP); callers check the type they accept. login() only establishes
 * web sessions for humans, after registration or recovery.
 *
 * Stateless on purpose: the identity is read from Security on every call, never kept in a property, so
 * a reused container (FrankenPHP worker) cannot carry it over to another request.
 */
class CurrentParticipantHelper
{
    private const string FIREWALL = 'main';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function get(): ?Participant
    {
        $user = $this->security->getUser();

        return $user instanceof Participant ? $user : null;
    }

    /** Starts an authenticated session (and rotates the session id) for a persisted human. */
    public function login(Participant $participant): void
    {
        if (ParticipantType::Human !== $participant->getType() || null === $participant->getId()) {
            throw new \InvalidArgumentException('Only persisted human participants can be logged in on the web.');
        }

        // Named explicitly: `main` also has the Bearer authenticator, which never establishes sessions.
        $this->security->login($participant, ParticipantSessionAuthenticator::class, self::FIREWALL);
    }
}
