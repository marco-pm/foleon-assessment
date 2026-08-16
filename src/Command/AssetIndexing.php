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
    private const int REFUSALS_LISTED = 10;

    public function __construct(
        private readonly AssetIndex $index,
        private readonly AssetIndexer $indexer,
        private readonly JsonAssetFile $assets,
        #[Autowire('%env(ELASTICSEARCH_URL)%')]
        private readonly string $elasticsearchUrl,
    ) {
    }

    #[AsCommand('app:index:load', 'create the index if needed and load the sample assets into it')]
    public function load(
        SymfonyStyle $io,
        #[Argument('id of a single asset to load, defaults to the whole sample file')]
        ?string $id = null,
        #[Option('drop the index first, discarding everything in it')]
        bool $recreate = false,
    ): int {
        try {
            $assets = null === $id ? $this->assets->all() : [$this->assets->find($id)];
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ([] === $assets) {
            $io->warning('The sample file holds no assets, so there is nothing to load.');
            return Command::FAILURE;
        }

        try {
            if ($recreate) {
                $this->index->drop();
            }

            $this->index->createIfMissing();
            $refused = $this->indexer->indexAll($io->progressIterate($assets));
        } catch (EmbeddingFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (ElasticsearchException|TransportException $e) {
            return $this->reportUnreachable($io, $e);
        }

        if ([] !== $refused) {
            return $this->reportRefusals($io, $refused, count($assets));
        }

        $io->success(sprintf(
            '%d asset%s indexed in "%s" and searchable.',
            count($assets),
            1 === count($assets) ? '' : 's',
            $this->index->name(),
        ));

        return Command::SUCCESS;
    }

    #[AsCommand('app:index:delete', 'remove a single asset from the index')]
    public function delete(
        SymfonyStyle $io,
        #[Argument('id of the asset to remove')]
        string $id,
    ): int {
        try {
            $wasIndexed = $this->index->delete($id);
        } catch (ElasticsearchException|TransportException $e) {
            return $this->reportUnreachable($io, $e);
        }

        if (!$wasIndexed) {
            $io->warning(sprintf('"%s" was not in "%s", so there was nothing to remove.', $id, $this->index->name()));

            return Command::SUCCESS;
        }

        $io->success(sprintf('"%s" is no longer in "%s".', $id, $this->index->name()));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $refused the reason given, per asset id
     */
    private function reportRefusals(SymfonyStyle $io, array $refused, int $total): int
    {
        $io->error(sprintf(
            '%d of %d assets are in "%s". Elasticsearch refused the rest:',
            $total - count($refused),
            $total,
            $this->index->name(),
        ));

        $listed = array_slice($refused, 0, self::REFUSALS_LISTED, preserve_keys: true);

        $io->listing(array_map(
            static fn (string $id, string $reason): string => sprintf('%s: %s', $id, $reason),
            array_keys($listed),
            array_values($listed),
        ));

        if (count($refused) > count($listed)) {
            $io->text(sprintf('... and %d more.', count($refused) - count($listed)));
        }

        return Command::FAILURE;
    }

    private function reportUnreachable(SymfonyStyle $io, Throwable $e): int
    {
        $io->error($e->getMessage());
        $io->note(sprintf('Is Elasticsearch up at %s? Check with: docker compose ps', $this->elasticsearchUrl));

        return Command::FAILURE;
    }
}
