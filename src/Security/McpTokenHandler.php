<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\ParticipantType;
use App\Helper\ParticipantCodeGeneratorHelper;
use App\Repository\ParticipantRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves `Authorization: Bearer <code>` on the `mcp` firewall, where <code> is a bot's Participant code.
 * Humans are never authenticated through MCP. The badge carries the participant UUID (never the code),
 * which the `participants` provider resolves back to the Participant.
 */
final readonly class McpTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ParticipantRepository $participantRepository,
        private ParticipantCodeGeneratorHelper $codeGenerator,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $code = $this->codeGenerator->normalize($accessToken);
        $participant = null === $code ? null : $this->participantRepository->findOneBy(['code' => $code]);

        // Same failure for malformed, unknown and human codes: nothing reveals which one it was.
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        return new UserBadge($participant->getUserIdentifier());
    }
}
