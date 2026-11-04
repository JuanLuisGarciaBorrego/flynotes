<?php

declare(strict_types=1);

namespace App\Enum;

enum AgentMissionStatus: string
{
    case Running = 'running';
    case WaitingToolApproval = 'waiting_tool_approval';
    case WaitingApproval = 'waiting_approval';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
