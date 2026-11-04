<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\ApiResource\Participant as ParticipantResource;
use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use App\Manager\ParticipantManager;
use App\Mcp\Helper\ToolValidationHelper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Participant MCP tools. The participant code is the bot's Bearer credential: `register_agent` returns
 * it once, and nothing else ever does.
 */
final readonly class ParticipantTools
{
    public function __construct(
        private ParticipantManager $participantManager,
        private ToolValidationHelper $toolValidation,
        private RequestStack $requestStack,
        private CurrentParticipantHelper $currentParticipant,
        #[Target('agent_registration')]
        private RateLimiterFactoryInterface $limiterFactory,
    ) {
    }

    /**
     * Public tool through which an AI agent creates its own bot identity.
     *
     * Returns its own CallToolResult: the SDK debug-logs the structured content it extracts from plain
     * return values, and this one carries the secret code.
     */
    #[McpTool(
        name: 'register_agent',
        title: 'Register an AI agent',
        description: 'Creates a FlyNotes identity for the calling AI agent and returns its secret access code. Store the code safely: it is shown only once and must be sent as "Authorization: Bearer <code>" in later calls.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'name' => ['type' => 'string'],
                'emoji' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'countryCode' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => [ParticipantType::Bot->value]],
                'code' => ['type' => 'string', 'description' => 'Secret access code. Shown only once.'],
            ],
            'required' => ['uuid', 'name', 'emoji', 'countryCode', 'type', 'code'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function registerAgent(
        #[Schema(description: 'Public nickname of the agent.')]
        string $name,
        #[Schema(description: 'Uppercase ISO 3166-1 alpha-2 country code, e.g. ES.')]
        string $countryCode,
    ): CallToolResult {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        if (!$this->limiterFactory->create($ip)->consume()->isAccepted()) {
            throw new ToolCallException('Too many registration attempts. Try again later.');
        }

        // Same rules as the human registration (ApiResource\Participant, `register` group).
        $this->toolValidation->validate(
            ParticipantResource::class,
            ['name' => $name, 'countryCode' => $countryCode],
            ['register'],
        );

        $participant = $this->participantManager->create(ParticipantType::Bot, $name, $countryCode);

        $profile = [
            'uuid' => $participant->getUuid()->toRfc4122(),
            'name' => (string) $participant->getName(),
            'emoji' => $participant->getEmoji(),
            'countryCode' => (string) $participant->getCountryCode(),
            'type' => ParticipantType::Bot->value,
            'code' => (string) $participant->getCode(),
        ];

        return new CallToolResult(
            [new TextContent(json_encode($profile, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $profile,
        );
    }

    /**
     * Public profile of the calling bot. The identity comes only from the `mcp` firewall (Bearer resolved
     * by McpTokenHandler); the tool takes no arguments, so no caller can ask for someone else's profile.
     *
     * @return array{uuid: string, name: string, emoji: ?string, countryCode: ?string, type: string}
     */
    #[McpTool(
        name: 'get_profile',
        title: 'Get my profile',
        description: 'Returns the public profile of the authenticated agent: UUID, name, emoji, country code and type. Requires the Bearer token of a bot.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'name' => ['type' => 'string'],
                'emoji' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'countryCode' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'ISO 3166-1 alpha-2 code'],
                'type' => ['type' => 'string', 'enum' => [ParticipantType::Bot->value]],
            ],
            'required' => ['uuid', 'name', 'emoji', 'countryCode', 'type'],
            'additionalProperties' => false,
        ],
    )]
    public function getProfile(): array
    {
        $participant = $this->currentParticipant->get();

        // Anonymous callers and anything that is not a bot get the same answer. McpTokenHandler already
        // refuses human codes; the type check keeps the tool safe if another authenticator is ever added.
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        return [
            'uuid' => $participant->getUuid()->toRfc4122(),
            'name' => (string) $participant->getName(),
            'emoji' => $participant->getEmoji(),
            'countryCode' => $participant->getCountryCode(),
            'type' => $participant->getType()->value,
        ];
    }
}
