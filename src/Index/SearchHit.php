<?php

declare(strict_types=1);

namespace App\Index;

final readonly class SearchHit
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public float $score,
    ) {
    }
}
