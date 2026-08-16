<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Embedding\EmbedderInterface;
use App\Embedding\EmbeddingFailedException;
use App\Index\AssetIndex;
use App\Index\AssetIndexer;
use App\Search\AssetSearch;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IndexTestCase extends KernelTestCase
{
    protected AssetIndex $index;
    protected AssetIndexer $indexer;
    protected AssetSearch $search;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->index = $container->get(AssetIndex::class);
        $this->indexer = $container->get(AssetIndexer::class);
        $this->search = $container->get(AssetSearch::class);

        try {
            $container->get(EmbedderInterface::class)->embed('a warm up call');
        } catch (EmbeddingFailedException $e) {
            self::markTestSkipped('The embedding model is not reachable: ' . $e->getMessage());
        }

        try {
            $this->index->drop();
            $this->index->createIfMissing();
        } catch (ElasticsearchException|TransportException $e) {
            self::markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->index->drop();
        } catch (ElasticsearchException|TransportException) {
        }

        parent::tearDown();
    }
}
