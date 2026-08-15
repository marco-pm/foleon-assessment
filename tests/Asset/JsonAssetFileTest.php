<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use App\Asset\JsonAssetFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonAssetFileTest extends TestCase
{
    /** @var list<string> */
    private array $writtenFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $file) {
            @unlink($file);
        }

        $this->writtenFiles = [];
    }

    public function testItReadsEveryAssetInTheFile(): void
    {
        $file = $this->fileContaining([
            ['id' => 'ast_1', 'name' => 'first', 'description' => 'the first description'],
            ['id' => 'ast_2', 'name' => 'second', 'description' => 'the second description'],
        ]);

        $assets = $file->all();

        self::assertCount(2, $assets);
        self::assertSame('ast_1', $assets[0]->id);
        self::assertSame('the second description', $assets[1]->description);
    }

    public function testItFindsAnAssetById(): void
    {
        $file = $this->fileContaining([
            ['id' => 'ast_1', 'name' => 'first', 'description' => 'the first description'],
            ['id' => 'ast_2', 'name' => 'second', 'description' => 'the second description'],
        ]);

        self::assertSame('second', $file->find('ast_2')->name);
    }

    public function testItRejectsAnAssetMissingAField(): void
    {
        $file = $this->fileWith('[{"id": "ast_1", "name": "no description follows"}]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/description/');

        $file->all();
    }

    /**
     * @param list<array<string, string>> $assets
     */
    private function fileContaining(array $assets): JsonAssetFile
    {
        return $this->fileWith(json_encode($assets, JSON_THROW_ON_ERROR));
    }

    private function fileWith(string $contents): JsonAssetFile
    {
        $path = tempnam(sys_get_temp_dir(), 'assets') . '.json';
        file_put_contents($path, $contents);
        $this->writtenFiles[] = $path;

        return new JsonAssetFile($path);
    }
}
