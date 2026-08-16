<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Asset\JsonAssetFile;
use App\Embedding\EmbedderInterface;
use App\Embedding\EmbeddingFailedException;
use App\Index\AssetIndex;
use App\Index\AssetIndexer;
use App\Search\AssetSearch;

trait SearchStack
{
    protected AssetIndex $index;
    protected AssetIndexer $indexer;
    protected AssetSearch $search;
    protected JsonAssetFile $sampleFile;

    protected function bootSearchStack(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->index = $container->get(AssetIndex::class);
        $this->indexer = $container->get(AssetIndexer::class);
        $this->search = $container->get(AssetSearch::class);
        $this->sampleFile = $container->get(JsonAssetFile::class);

        try {
            $container->get(EmbedderInterface::class)->embed('a warm up call');
        } catch (EmbeddingFailedException $e) {
            self::markTestSkipped('The embedding model is not reachable: ' . $e->getMessage());
        }
    }
}
