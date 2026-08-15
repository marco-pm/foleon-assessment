<?php

declare(strict_types=1);

namespace App\Embedding;

interface EmbedderInterface
{
    /**
     * @return list<float>
     *
     * @throws EmbeddingFailedException
     */
    public function embed(string $text): array;
}
