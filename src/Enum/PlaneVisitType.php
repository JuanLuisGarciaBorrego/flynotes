<?php

declare(strict_types=1);

namespace App\Enum;

enum PlaneVisitType: string
{
    case View = 'view';
    case Participation = 'participation';
}
