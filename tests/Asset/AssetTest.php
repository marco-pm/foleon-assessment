<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use App\Asset\Asset;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    public function testItKeepsTheFieldsItWasGiven(): void
    {
        $asset = new Asset('ast_8831', 'Q3_deck_FINAL_v2', 'A quarterly business review deck.');

        self::assertSame('ast_8831', $asset->id);
        self::assertSame('Q3_deck_FINAL_v2', $asset->name);
        self::assertSame('A quarterly business review deck.', $asset->description);
    }

    #[DataProvider('incompleteAssets')]
    public function testItRejectsAnIncompleteAsset(string $id, string $name, string $description): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Asset($id, $name, $description);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function incompleteAssets(): iterable
    {
        yield 'no id' => ['', 'a name', 'a description'];
        yield 'blank id' => ['   ', 'a name', 'a description'];
        yield 'no name' => ['ast_1', '', 'a description'];
        yield 'no description' => ['ast_1', 'a name', ''];
        yield 'blank description' => ['ast_1', 'a name', " \n\t "];
    }
}
