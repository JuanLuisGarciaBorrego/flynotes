<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Dto\AgentMissionDecision;
use App\Dto\AgentMissionToolDecision;
use App\Entity\AgentMission as AgentMissionEntity;
use App\Entity\AgentMissionStep;
use App\Enum\AgentMissionStatus;
use App\Enum\AgentMissionStepType;
use App\Exception\AiUnavailableException;
use App\Exception\MissionDecisionConflictException;
use App\OpenApi\FlyNotesOpenApiFactory;
use App\State\AgentMissionCurrentProvider;
use App\State\AgentMissionDecisionProcessor;
use App\State\AgentMissionDecisionProvider;
use App\State\AgentMissionProcessor;
use App\State\AgentMissionProvider;
use App\State\AgentMissionToolDecisionProcessor;
use App\State\AgentMissionToolDecisionProvider;
use App\Validator\MissionAvailableToStart;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'AgentMission',
    operations: [
        new Get(
            uriTemplate: '/agent_missions/{uuid}',
            requirements: ['uuid' => Requirement::UUID],
            openapi: new Model\Operation(
                operationId: 'readAgentMission',
                tags: ['Sky Pulse missions'],
                summary: 'Read a Sky Pulse mission',
                description: 'Returns the status of a mission, what it is waiting for and its public history. Anyone can follow a mission; only humans can decide on it.',
            ),
            // API Platform omits null values by default: here they are part of the response.
            normalizationContext: ['groups' => ['agent_mission:read'], 'skip_null_values' => false, 'preserve_empty_objects' => true],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: AgentMissionProvider::class,
            throwOnNotFound: true,
        ),
        // How a visitor finds the mission without knowing its UUID: the active one, or else the last one.
        new Get(
            uriTemplate: '/agent_missions/current',
            uriVariables: [],
            openapi: new Model\Operation(
                operationId: 'findCurrentAgentMission',
                tags: ['Sky Pulse missions'],
                summary: 'Find the current Sky Pulse mission',
                description: 'Returns the active mission or, if there is none, the most recent one, so a visitor can follow it without knowing its UUID.',
            ),
            // `item_uri_template`: its @id is the mission's own, not this moving address.
            normalizationContext: ['groups' => ['agent_mission:read'], 'skip_null_values' => false, 'preserve_empty_objects' => true, 'item_uri_template' => '_api_/agent_missions/{uuid}_get'],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: AgentMissionCurrentProvider::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/agent_missions',
            status: Response::HTTP_CREATED,
            openapi: new Model\Operation(
                operationId: 'startAgentMission',
                tags: ['Sky Pulse missions'],
                responses: [
                    201 => new Model\Response(description: 'Mission created. `status` is `waiting_tool_approval`, or `failed` if the model could not choose a tool.'),
                    422 => new Model\Response(description: 'Another mission is active.'),
                    503 => new Model\Response(description: 'AI is temporarily unavailable: no mission was created.'),
                ],
                summary: 'Start a Sky Pulse mission',
                description: 'Asks the agent to choose one research tool and leaves that call waiting for human approval: nothing runs yet. Only one mission can be active at a time. Send an empty JSON object: a body with nothing in it is rejected.',
                requestBody: new Model\RequestBody(description: 'An empty JSON object: `{}`.', required: true),
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            exceptionToStatus: [AiUnavailableException::class => Response::HTTP_SERVICE_UNAVAILABLE],
            normalizationContext: ['groups' => ['agent_mission:read'], 'preserve_empty_objects' => true],
            security: "is_granted('ROLE_HUMAN')",
            processor: AgentMissionProcessor::class,
        ),
        new Post(
            uriTemplate: '/agent_missions/{uuid}/tool-decision',
            uriVariables: ['uuid'],
            requirements: ['uuid' => Requirement::UUID],
            status: Response::HTTP_OK,
            openapi: new Model\Operation(
                operationId: 'decideAgentMissionTool',
                tags: ['Sky Pulse missions'],
                responses: [
                    200 => new Model\Response(description: 'Decision applied. `status` is `waiting_approval` with a proposal, `rejected`, or `failed` if the model failed after the research.'),
                    422 => new Model\Response(description: 'The mission is not waiting for this decision.'),
                    503 => new Model\Response(description: 'AI is temporarily unavailable, so the tool cannot be approved. The mission keeps waiting, and it can still be rejected.'),
                ],
                summary: 'Approve or reject the research tool',
                description: 'First human approval. Approving runs the pending tool and resumes the same conversation with its real result, until the agent proposes a report. Rejecting ends the mission, and the tool never runs.',
                requestBody: new Model\RequestBody(description: '`accepted: true` to run the tool, `accepted: false` to end the mission.', required: true),
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            exceptionToStatus: [
                MissionDecisionConflictException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
                AiUnavailableException::class => Response::HTTP_SERVICE_UNAVAILABLE,
            ],
            normalizationContext: ['groups' => ['agent_mission:read'], 'preserve_empty_objects' => true],
            denormalizationContext: [
                'groups' => ['agent_mission:tool_decision'],
                // Fills the decision built by the provider, which carries the mission from the URL.
                SerializerContextBuilderInterface::ASSIGN_OBJECT_TO_POPULATE => true,
            ],
            security: "is_granted('ROLE_HUMAN')",
            input: AgentMissionToolDecision::class,
            provider: AgentMissionToolDecisionProvider::class,
            processor: AgentMissionToolDecisionProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/agent_missions/{uuid}/decision',
            uriVariables: ['uuid'],
            requirements: ['uuid' => Requirement::UUID],
            status: Response::HTTP_OK,
            openapi: new Model\Operation(
                operationId: 'decideAgentMissionReport',
                tags: ['Sky Pulse missions'],
                responses: [
                    200 => new Model\Response(description: 'Decision applied. `status` is `completed` and `publishedPlane` holds the new plane, or `rejected`.'),
                    422 => new Model\Response(description: 'The mission is not waiting for this decision.'),
                ],
                summary: 'Approve or reject the report',
                description: 'Second human approval. Approving publishes the proposed report as a Sky Pulse paper plane, without calling the model again. Rejecting ends the mission with nothing published.',
                requestBody: new Model\RequestBody(description: '`accepted: true` to publish the report, `accepted: false` to discard it.', required: true),
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            exceptionToStatus: [MissionDecisionConflictException::class => Response::HTTP_UNPROCESSABLE_ENTITY],
            normalizationContext: ['groups' => ['agent_mission:read']],
            denormalizationContext: [
                'groups' => ['agent_mission:decision'],
                // Fills the decision built by the provider, which carries the mission from the URL.
                SerializerContextBuilderInterface::ASSIGN_OBJECT_TO_POPULATE => true,
            ],
            security: "is_granted('ROLE_HUMAN')",
            input: AgentMissionDecision::class,
            provider: AgentMissionDecisionProvider::class,
            processor: AgentMissionDecisionProcessor::class,
            throwOnNotFound: true,
        ),
    ],
)]
#[MissionAvailableToStart]
final class AgentMission
{
    /**
     * Tools whose results may be read by anyone, because they only return aggregated statistics. Written out on
     * purpose: a new tool stays private until someone decides that its results can be published.
     */
    private const array TOOLS_WITH_PUBLIC_RESULT = ['get_sky_emojis', 'get_sky_countries', 'get_sky_activity'];

    #[ApiProperty(writable: false, identifier: true, description: 'Public identifier of the mission.', example: '8f14e45f-ceea-467f-a8d9-1a2b3c4d5e6f')]
    #[Groups(['agent_mission:read'])]
    public ?string $uuid = null;

    #[ApiProperty(writable: false, description: '`running`: the agent is working. `waiting_tool_approval` and `waiting_approval`: waiting for a human. `completed`: report published. `rejected`: a human said no. `failed`: the model failed.', example: 'waiting_tool_approval')]
    #[Groups(['agent_mission:read'])]
    public ?AgentMissionStatus $status = null;

    #[ApiProperty(writable: false, description: 'When the mission started, in ISO 8601.', example: '2026-11-04T18:30:00+00:00')]
    #[Groups(['agent_mission:read'])]
    public ?string $createdAt = null;

    #[ApiProperty(writable: false, description: 'Title of the proposed report. Null until there is a proposal.', example: 'Where the planes come from')]
    #[Groups(['agent_mission:read'])]
    public ?string $title = null;

    #[ApiProperty(writable: false, description: 'Message of the proposed report, which becomes a paper plane if approved. Null until there is a proposal.', example: 'Spain leads the sky this week, with France close behind.')]
    #[Groups(['agent_mission:read'])]
    public ?string $proposedMessage = null;

    #[ApiProperty(writable: false, description: 'Whether the mission is waiting for a human decision.', example: true)]
    #[Groups(['agent_mission:read'])]
    public bool $requiresApproval = false;

    #[ApiProperty(writable: false, description: 'Model tokens used by the mission so far.', example: 1250)]
    #[Groups(['agent_mission:read'])]
    public int $totalTokens = 0;

    /**
     * The research tool waiting for a human, while there is one.
     *
     * @var array{name: string, arguments: \ArrayObject<string, mixed>}|null
     */
    #[ApiProperty(writable: false, description: 'The research tool waiting for approval, with its arguments. Null when there is none.')]
    #[Groups(['agent_mission:read'])]
    public ?array $pendingTool = null;

    /** UUID of the plane published after approval; absent until then. */
    #[ApiProperty(writable: false, description: 'UUID of the plane published after approval. Null until then.')]
    #[Groups(['agent_mission:read'])]
    public ?string $publishedPlane = null;

    /**
     * @var list<array{
     *     sequence: int,
     *     type: string,
     *     toolName: ?string,
     *     result?: mixed,
     *     createdAt: string
     * }>
     */
    #[ApiProperty(writable: false, description: 'Public history of the mission, oldest first. Results only appear for tools that return aggregated statistics.')]
    #[Groups(['agent_mission:read'])]
    public array $steps = [];

    public static function fromEntity(AgentMissionEntity $mission): self
    {
        $resource = new self();
        $resource->uuid = $mission->getUuid()->toRfc4122();
        $resource->status = $mission->getStatus();
        $resource->createdAt = $mission->getCreatedAt()?->format(\DATE_ATOM);
        $resource->title = $mission->getTitle();
        $resource->proposedMessage = $mission->getProposedMessage();
        $resource->requiresApproval = \in_array($mission->getStatus(), [AgentMissionStatus::WaitingToolApproval, AgentMissionStatus::WaitingApproval], true);
        if (AgentMissionStatus::WaitingToolApproval === $mission->getStatus()) {
            $resource->pendingTool = [
                'name' => (string) $mission->getPendingToolName(),
                // An object, so that no arguments are `{}` in JSON and not `[]`.
                'arguments' => new \ArrayObject($mission->getPendingToolArguments() ?? []),
            ];
        }
        $resource->totalTokens = $mission->getTotalTokens();
        $resource->publishedPlane = $mission->getPublishedPlane()?->getUuid()->toRfc4122();

        foreach ($mission->getSteps() as $step) {
            $resource->steps[] = [
                'sequence' => $step->getSequence(),
                'type' => $step->getType()->value,
                'toolName' => $step->getToolName(),
                ...self::publicResult($step),
                'createdAt' => $step->getCreatedAt()->format(\DATE_ATOM),
            ];
        }

        return $resource;
    }

    /**
     * The only part of a step's payload that leaves the API. Absent in old steps, which did not store it.
     *
     * @return array{result?: mixed}
     */
    private static function publicResult(AgentMissionStep $step): array
    {
        $payload = $step->getPayload() ?? [];

        if (AgentMissionStepType::ToolSucceeded !== $step->getType()
            || !\in_array($step->getToolName(), self::TOOLS_WITH_PUBLIC_RESULT, true)
            || !\array_key_exists('result', $payload)) {
            return [];
        }

        return ['result' => $payload['result']];
    }
}
