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

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>> one vector per text, in the order the texts came in
     *
     * @throws EmbeddingFailedException
     */
    public function embedAll(array $texts): array;
}
