<?php

declare(strict_types=1);

namespace App\Search;

use App\Embedding\EmbedderInterface;
use App\Embedding\EmbeddingFailedException;
use App\Index\AssetIndex;
use App\Index\SearchHit;

final class AssetSearch
{
    public const int TOP_HITS = 10;

    public function __construct(
        private readonly EmbedderInterface $embedder,
        private readonly AssetIndex $index,
    ) {
    }

    /**
     * @return list<SearchHit>
     *
     * @throws EmbeddingFailedException
     */
    public function search(string $query): array
    {
        return $this->index->search($this->embedder->embed($query), self::TOP_HITS);
    }
}
