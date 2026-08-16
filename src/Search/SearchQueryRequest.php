<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\Validator\Constraints as Assert;

final class SearchQueryRequest
{
    private const int MAX_LENGTH = 500;

    public function __construct(
        #[Assert\NotBlank(message: 'Describe what you are looking for, for example /search?q=hiring plans.')]
        #[Assert\Length(max: self::MAX_LENGTH)]
        public readonly string $q = '',
    ) {
    }
}
