<?php

declare(strict_types=1);

namespace App\Tests\Integration\Index;

use App\Asset\Asset;
use App\Tests\Integration\IndexTestCase;

final class AssetLifecycleTest extends IndexTestCase
{
    public function testAnUpdatedAssetIsSearchableByItsCurrentDescriptionOnly(): void
    {
        $this->indexer->index(new Asset('ast_1', 'notes', 'A collection of pasta recipes with tomato sauce'));
        $this->indexer->index(new Asset('ast_1', 'notes', 'The annual fire safety inspection report'));

        $hits = $this->search->search('fire safety inspection');

        self::assertCount(1, $hits, 'updating an asset must not leave a second document behind');
        self::assertSame('ast_1', $hits[0]->id);
        self::assertSame('The annual fire safety inspection report', $hits[0]->description);

        // the description it used to have must be gone from the entire index
        foreach ($this->search->search('pasta recipes tomato sauce') as $hit) {
            self::assertStringNotContainsString('pasta', $hit->description);
        }
    }

    public function testADeletedAssetStopsBeingSearchable(): void
    {
        $this->indexer->index(new Asset('ast_1', 'notes', 'The annual fire safety inspection report'));

        self::assertCount(1, $this->search->search('fire safety inspection'), 'the asset has to be there before it can be removed');

        self::assertTrue($this->index->delete('ast_1'), 'the asset was in the index, so the delete removed something');

        self::assertSame([], $this->search->search('fire safety inspection'));
    }

    public function testDeletingAnAssetThatWasNeverIndexedIsNotAFailure(): void
    {
        self::assertFalse($this->index->delete('ast_never_seen'));
    }
}
