<?php

declare(strict_types=1);

namespace App\Enum;

enum ParticipantType: string
{
    case Human = 'human';
    case Bot = 'bot';
}
