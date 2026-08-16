<?php

declare(strict_types=1);

namespace App\Index;

use App\Asset\Asset;
use App\Embedding\EmbedderInterface;
use App\Embedding\EmbeddingFailedException;

/**
 * Turns an asset into an indexed document.
 */
final class AssetIndexer
{
    public function __construct(
        private readonly EmbedderInterface $embedder,
        private readonly AssetIndex $index,
    ) {
    }

    /**
     * @throws EmbeddingFailedException
     */
    public function index(Asset $asset): void
    {
        $this->index->save($asset, $this->embedder->embed($asset->description));
    }

    /**
     * @param iterable<Asset> $assets
     *
     * @throws EmbeddingFailedException
     */
    public function indexAll(iterable $assets): void
    {
        foreach ($assets as $asset) {
            $this->index->save($asset, $this->embedder->embed($asset->description), waitForRefresh: false);
        }

        $this->index->refresh();
    }
}
