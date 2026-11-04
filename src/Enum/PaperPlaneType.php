<?php

declare(strict_types=1);

namespace App\Enum;

enum PaperPlaneType: string
{
    case Welcome = 'welcome';
    case Custom = 'custom';
}
