<?php

declare(strict_types=1);

namespace App\Embedding;

use RuntimeException;
use Throwable;

final class EmbeddingFailedException extends RuntimeException
{
    public static function unreachable(string $endpoint, string $model, Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach the embedding model "%s" at %s: %s', $model, $endpoint, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function unexpectedResponse(string $model, string $reason): self
    {
        return new self(sprintf('The embedding model "%s" returned an unusable response: %s', $model, $reason));
    }
}
