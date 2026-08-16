<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IndexTestCase extends KernelTestCase
{
    use SearchStack;

    protected function setUp(): void
    {
        $this->bootSearchStack();

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
