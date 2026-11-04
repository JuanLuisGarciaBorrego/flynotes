<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Only declares the request to the model. ToolApprovalListener intercepts every call before it gets here,
 * and publishing happens later from AgentMissionManager::decide(), after a human approves. This body has no
 * way to publish on purpose: should the interception ever be lost, the call fails instead of publishing.
 */
#[AsTool(
    name: self::NAME,
    description: 'Requests the publication of your paper plane report. It does not publish anything: a human reviews the request and decides. Call it once, when your investigation is finished.',
)]
final readonly class PublishSkyReport
{
    public const string NAME = 'publish_sky_report';

    /**
     * @param string $title   short title of the mission, at most 150 characters
     * @param string $message paper plane message based on your findings, between 15 and 250 characters
     */
    public function __invoke(string $title, string $message): string
    {
        throw new \LogicException('Publishing a Sky Pulse report requires human approval.');
    }
}
