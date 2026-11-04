<?php

declare(strict_types=1);

namespace App\Ai\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PaperPlaneAiResponse
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 250)]
        public string $text,

        #[Assert\Length(max: 50)]
        public ?string $tone = null,
    ) {
    }
}
