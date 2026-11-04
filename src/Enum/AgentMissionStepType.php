<?php

declare(strict_types=1);

namespace App\Enum;

enum AgentMissionStepType: string
{
    case Started = 'started';
    case ToolCalled = 'tool_called';
    case ToolSucceeded = 'tool_succeeded';
    case ToolFailed = 'tool_failed';
    case ProposalCreated = 'proposal_created';
    case ApprovalRequested = 'approval_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Resumed = 'resumed';
    case Completed = 'completed';
    case Failed = 'failed';
}
