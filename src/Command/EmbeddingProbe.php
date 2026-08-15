<?php

declare(strict_types=1);

namespace App\Command;

use App\Asset\JsonAssetFile;
use App\Embedding\EmbedderInterface;
use App\Embedding\EmbeddingFailedException;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EmbeddingProbe
{
    private const int PREVIEW_COMPONENTS = 5;

    public function __construct(
        private readonly JsonAssetFile     $assets,
        private readonly EmbedderInterface $embedder,
        #[Autowire('%env(EMBEDDING_BASE_URL)%')]
        private readonly string            $baseUrl,
        #[Autowire('%env(EMBEDDING_MODEL)%')]
        private readonly string            $model,
        #[Autowire('%env(int:EMBEDDING_DIMENSIONS)%')]
        private readonly int               $expectedDimensions,
    ) {
    }

    /**
     * @throws RuntimeException when the sample file is unreadable or holds no such asset
     */
    #[AsCommand('app:asset:embed', 'embed one asset description and show the resulting vector')]
    public function probe(
        SymfonyStyle $io,
        #[Argument('id of the asset to embed, defaults to the first one in the sample file')]
        ?string $id = null,
    ): int {
        $asset = null === $id ? $this->assets->first() : $this->assets->find($id);

        try {
            $startedAt = microtime(true);
            $vector = $this->embedder->embed($asset->description);
            $elapsed = microtime(true) - $startedAt;
        } catch (EmbeddingFailedException $e) {
            $io->error($e->getMessage());
            $io->note(sprintf('Is %s serving the model "%s"? Pull it with: docker compose exec ollama ollama pull %s', $this->baseUrl, $this->model, $this->model));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['asset' => $asset->id],
            ['name' => $asset->name],
            ['model' => $this->model],
            ['dimensions' => (string) count($vector)],
            ['took' => sprintf('%.2fs', $elapsed)],
        );

        $preview = array_map(
            static fn (float $component): string => sprintf('%+.5f', $component),
            array_slice($vector, 0, self::PREVIEW_COMPONENTS),
        );
        $io->writeln(sprintf('first %d components: %s, ...', self::PREVIEW_COMPONENTS, implode(', ', $preview)));

        if (count($vector) !== $this->expectedDimensions) {
            $io->warning(sprintf('EMBEDDING_DIMENSIONS is %d but the model returned %d components.', $this->expectedDimensions, count($vector)));

            return Command::FAILURE;
        }

        $io->success('The embedding model is reachable and answers with the expected number of dimensions.');

        return Command::SUCCESS;
    }
}
