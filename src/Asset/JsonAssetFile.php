<?php

declare(strict_types=1);

namespace App\Asset;

use JsonException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The sample library on disk.
 */
final class JsonAssetFile
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/data/assets.json')]
        private readonly string $path,
    ) {
    }

    /**
     * @return list<Asset>
     *
     * @throws RuntimeException when the file is missing or not the expected shape
     */
    public function all(): array
    {
        if (!is_readable($this->path)) {
            throw new RuntimeException(sprintf('Cannot read the asset file at "%s".', $this->path));
        }

        $contents = file_get_contents($this->path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('Cannot read the asset file at "%s".', $this->path));
        }

        try {
            $rows = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('The asset file at "%s" is not valid JSON: %s', $this->path, $e->getMessage()), previous: $e);
        }

        if (!is_array($rows)) {
            throw new RuntimeException(sprintf('The asset file at "%s" should contain a list of assets.', $this->path));
        }

        return array_values(array_map($this->toAsset(...), array_keys($rows), $rows));
    }

    /**
     * @throws RuntimeException when no asset carries that id
     */
    public function find(string $id): Asset
    {
        foreach ($this->all() as $asset) {
            if ($id === $asset->id) {
                return $asset;
            }
        }

        throw new RuntimeException(sprintf('No asset with id "%s" in "%s".', $id, $this->path));
    }

    private function toAsset(int|string $position, mixed $row): Asset
    {
        if (!is_array($row)) {
            throw new RuntimeException(sprintf('Entry %s of "%s" is not an object.', $position, $this->path));
        }

        foreach (['id', 'name', 'description'] as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                throw new RuntimeException(sprintf('Entry %s of "%s" is missing a string "%s".', $position, $this->path, $field));
            }
        }

        return new Asset($row['id'], $row['name'], $row['description']);
    }
}
