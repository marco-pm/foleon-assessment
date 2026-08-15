<?php

declare(strict_types=1);

namespace App\Command;

use App\Asset\JsonAssetFile;
use App\Embedding\EmbeddingFailedException;
use App\Index\AssetIndex;
use App\Index\AssetIndexer;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final class AssetIndexing
{
    public function __construct(
        private readonly AssetIndex $index,
        private readonly AssetIndexer $indexer,
        private readonly JsonAssetFile $assets,
        #[Autowire('%env(ELASTICSEARCH_URL)%')]
        private readonly string $elasticsearchUrl,
    ) {
    }

    #[AsCommand('app:index:create', 'create the Elasticsearch index with its mapping')]
    public function create(
        SymfonyStyle $io,
        #[Option('drop the index first, discarding everything in it')]
        bool $recreate = false,
    ): int {
        try {
            if ($recreate) {
                $this->index->drop();
            }

            $created = $this->index->createIfMissing();
        } catch (ElasticsearchException|TransportException $e) {
            return $this->reportUnreachable($io, $e);
        }

        if (!$created) {
            $io->note(sprintf('Index "%s" already exists, nothing to do. Use --recreate to rebuild it.', $this->index->name()));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Index "%s" created.', $this->index->name()));

        return Command::SUCCESS;
    }

    /**
     * @throws RuntimeException when the sample file is unreadable or holds no such asset
     */
    #[AsCommand('app:index:asset', 'embed one asset from the sample file and put it in the index')]
    public function indexAsset(
        SymfonyStyle $io,
        #[Argument('id of the asset to index, defaults to the first one in the sample file')]
        ?string $id = null,
    ): int {
        $asset = null === $id ? $this->assets->first() : $this->assets->find($id);

        try {
            $this->index->createIfMissing();
            $this->indexer->index($asset);
        } catch (EmbeddingFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (ElasticsearchException|TransportException $e) {
            return $this->reportUnreachable($io, $e);
        }

        $io->success(sprintf('Asset "%s" is indexed in "%s" and searchable.', $asset->id, $this->index->name()));

        return Command::SUCCESS;
    }

    private function reportUnreachable(SymfonyStyle $io, Throwable $e): int
    {
        $io->error($e->getMessage());
        $io->note(sprintf('Is Elasticsearch up at %s? Check with: docker compose ps', $this->elasticsearchUrl));

        return Command::FAILURE;
    }
}
