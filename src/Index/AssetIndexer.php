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
    private const int BATCH_SIZE = 25;

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
     * @return array<string, string> the reason given, per asset id that was refused
     *
     * @throws EmbeddingFailedException
     */
    public function indexAll(iterable $assets): array
    {
        $refused = [];
        $batch = [];

        foreach ($assets as $asset) {
            $batch[] = $asset;

            if (self::BATCH_SIZE === count($batch)) {
                $refused += $this->indexBatch($batch);
                $batch = [];
            }
        }

        $refused += $this->indexBatch($batch);

        $this->index->refresh(); // we only do the refresh when all batches have been indexed

        return $refused;
    }

    /**
     * @param list<Asset> $batch
     *
     * @return array<string, string>
     *
     * @throws EmbeddingFailedException
     */
    private function indexBatch(array $batch): array
    {
        if ([] === $batch) {
            return [];
        }

        $vectors = $this->embedder->embedAll(array_map(
            static fn (Asset $asset): string => $asset->description,
            $batch,
        ));

        return $this->index->saveAll(array_map(
            static fn (Asset $asset, array $vector): array => [$asset, $vector],
            $batch,
            $vectors,
        ));
    }
}
