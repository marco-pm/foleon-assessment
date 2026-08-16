<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Asset\Asset;
use App\Tests\Integration\IndexTestCase;

final class SemanticSearchTest extends IndexTestCase
{
    public function testItFindsAnAssetWhoseDescriptionNeverUsesTheQueryWords(): void
    {
        $deck = new Asset(
            'ast_8831',
            'Q3_deck_FINAL_v2',
            'A quarterly business review deck: revenue against target by region, a churn breakdown for enterprise accounts, and a closing slide of headcount plans for the next two quarters.',
        );
        $this->indexer->index($deck);
        $this->indexer->index(new Asset('ast_kitchen', 'IMG_4471', 'Photographs of a kitchen renovation, before and after, with close ups of the tiling.'));
        $this->indexer->index(new Asset('ast_birds', 'rec_02', 'A field recording of birdsong in a wetland reserve at dawn.'));

        $hits = $this->search->search('hiring');

        self::assertSame('ast_8831', $hits[0]->id);
        self::assertStringNotContainsStringIgnoringCase('hiring', $hits[0]->description, 'the point of the test is that the word is absent');
    }
}
