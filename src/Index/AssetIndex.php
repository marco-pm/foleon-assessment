<?php

declare(strict_types=1);

namespace App\Index;

use App\Asset\Asset;
use Elastic\Elasticsearch\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AssetIndex
{
    public function __construct(
        private readonly Client $client,
        #[Autowire('%env(ELASTICSEARCH_INDEX)%')]
        private readonly string $name,
        #[Autowire('%env(int:EMBEDDING_DIMENSIONS)%')]
        private readonly int $dimensions,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function exists(): bool
    {
        return $this->client->indices()->exists(['index' => $this->name])->asBool();
    }

    /**
     * @return bool whether the index had to be created
     */
    public function createIfMissing(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $this->client->indices()->create([
            'index' => $this->name,
            'body' => $this->definition(),
        ]);

        return true;
    }

    public function drop(): void
    {
        if (!$this->exists()) {
            return;
        }

        $this->client->indices()->delete(['index' => $this->name]);
    }

    /**
     * @param list<float> $vector
     */
    public function save(Asset $asset, array $vector): void
    {
        $this->client->index([
            'index' => $this->name,
            'id' => $asset->id,
            'refresh' => 'wait_for', // make sure the asset is immediately searchable
            'body' => [
                'name' => $asset->name,
                'description' => $asset->description,
                'embedding' => $vector,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'mappings' => [
                'properties' => [
                    'name' => [
                        'type' => 'text',
                        'fields' => [
                            'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
                        ],
                    ],
                    'description' => ['type' => 'text'],
                    'embedding' => [
                        'type' => 'dense_vector',
                        'dims' => $this->dimensions,
                        'index' => true,
                        'similarity' => 'cosine',
                    ],
                ],
            ],
        ];
    }
}
