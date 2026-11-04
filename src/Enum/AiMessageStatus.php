<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a plane's AI reinterpretation (null on the plane = none was ever generated):
 * Pending → Accepted (kept and shown publicly) or Pending → Rejected (discarded). Both decisions are final.
 */
enum AiMessageStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
