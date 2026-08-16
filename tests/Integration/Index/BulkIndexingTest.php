<?php

declare(strict_types=1);

namespace App\Tests\Integration\Index;

use App\Asset\Asset;
use App\Tests\Integration\IndexTestCase;

final class BulkIndexingTest extends IndexTestCase
{
    public function testEveryAssetInABatchIsSearchableWhenTheLoadReturns(): void
    {
        $refused = $this->indexer->indexAll($this->sampleFile->all());

        self::assertSame([], $refused, 'nothing in the sample file should be refused');

        // indexAll declines the per document refresh, so this also pins the one at the end
        self::assertNotSame([], $this->search->search('quarterly revenue against target'));
    }

    public function testARefusedDocumentIsReportedAndTheRestOfTheBatchStillLands(): void
    {
        $good = new Asset('ast_good', 'notes', 'The annual fire safety inspection report');
        $vector = $this->embedder->embed($good->description);

        $refused = $this->index->saveAll([
            [$good, $vector],
            // the mapping fixes the number of dimensions, so this one document cannot be written
            [new Asset('ast_wrong_dimensions', 'notes', 'A collection of pasta recipes'), [0.1, 0.2, 0.3]],
        ]);

        self::assertSame(['ast_wrong_dimensions'], array_keys($refused), 'the refusal has to name the asset');
        self::assertStringContainsStringIgnoringCase('dimension', $refused['ast_wrong_dimensions']);

        $this->index->refresh();

        $hits = $this->search->search('fire safety inspection');
        self::assertCount(1, $hits, 'one bad document must not cost the batch');
        self::assertSame('ast_good', $hits[0]->id);
    }
}
