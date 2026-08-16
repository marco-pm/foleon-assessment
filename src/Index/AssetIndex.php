<?php

declare(strict_types=1);

namespace App\Index;

use App\Asset\Asset;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AssetIndex
{
    private const string VECTOR_FIELD = 'embedding';
    private const int CANDIDATES_PER_HIT = 10;
    private const int NOT_FOUND = 404;

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
     *
     * @param list<float> $vector
     */
    public function save(Asset $asset, array $vector): void
    {
        $this->client->index([
            'index' => $this->name,
            'id' => $asset->id,
            'refresh' => 'wait_for', // wait for the refresh to be done before returning
            'body' => [
                'name' => $asset->name,
                'description' => $asset->description,
                self::VECTOR_FIELD => $vector,
            ],
        ]);
    }

    /**
     * @param list<array{Asset, list<float>}> $documents each asset paired with its vector
     *
     * @return array<string, string> the reason given, per asset id that was refused
     */
    public function saveAll(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $body = [];

        foreach ($documents as [$asset, $vector]) {
            $body[] = ['index' => ['_id' => $asset->id]];
            $body[] = [
                'name' => $asset->name,
                'description' => $asset->description,
                self::VECTOR_FIELD => $vector,
            ];
        }

        $response = $this->client->bulk([
            'index' => $this->name,
            'refresh' => 'false', // refresh is done only once (waiting per batch costs a second each and buys nothing)
            'body' => $body,
        ])->asArray();

        // Unlike a single write, Elasticsearch answers 200 even when it refuses individual documents, so the refusals
        // are handed back to the caller instead of aborting
        return $this->refusalsIn($response);
    }

    /**
     * @return bool whether the asset was there to begin with
     */
    public function delete(string $id): bool
    {
        try {
            $this->client->delete([
                'index' => $this->name,
                'id' => $id,
                'refresh' => 'wait_for',
            ]);
        } catch (ClientResponseException $e) {
            // both an unknown id and a missing index answer 404
            if (self::NOT_FOUND === $e->getCode()) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function refresh(): void
    {
        $this->client->indices()->refresh(['index' => $this->name]);
    }

    /**
     * @param list<float> $vector
     *
     * @return list<SearchHit>
     */
    public function search(array $vector, int $size): array
    {
        $response = $this->client->search([
            'index' => $this->name,
            'body' => [
                'knn' => [
                    'field' => self::VECTOR_FIELD,
                    'query_vector' => $vector,
                    'k' => $size,
                    'num_candidates' => $size * self::CANDIDATES_PER_HIT,
                ],
                'size' => $size,
                '_source' => ['name', 'description'],
            ],
        ])->asArray();

        return array_values(array_map(
            static fn (array $hit): SearchHit => new SearchHit(
                $hit['_id'],
                $hit['_source']['name'],
                $hit['_source']['description'],
                (float) $hit['_score'],
            ),
            $response['hits']['hits'],
        ));
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, string>
     */
    private function refusalsIn(array $response): array
    {
        if (true !== ($response['errors'] ?? false)) {
            return [];
        }

        $refusals = [];

        foreach ($response['items'] ?? [] as $item) {
            $outcome = $item['index'] ?? [];

            if (!isset($outcome['error'])) {
                continue;
            }

            $refusals[$outcome['_id']] = $outcome['error']['reason'] ?? $outcome['error']['type'] ?? 'refused without a reason';
        }

        return $refusals;
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
                    self::VECTOR_FIELD => [
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
