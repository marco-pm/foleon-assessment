<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Index\AssetIndex;
use App\Index\SearchHit;
use App\Tests\Integration\SearchStack;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SampleCorpusTest extends KernelTestCase
{
    use SearchStack;

    private static bool $corpusLoaded = false;

    protected function setUp(): void
    {
        $this->bootSearchStack();

        if (self::$corpusLoaded) {
            return;
        }

        try {
            $this->index->drop();
            $this->index->createIfMissing();
            $this->indexer->indexAll($this->sampleFile->all());
        } catch (ElasticsearchException|TransportException $e) {
            self::markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
        }

        self::$corpusLoaded = true;
    }

    public static function tearDownAfterClass(): void
    {
        self::bootKernel();

        try {
            static::getContainer()->get(AssetIndex::class)->drop();
        } catch (ElasticsearchException|TransportException) {
        }

        self::ensureKernelShutdown();
        self::$corpusLoaded = false;

        parent::tearDownAfterClass();
    }

    public function testItFindsAssetsThatShareNoneOfTheWordsInTheQuery(): void
    {
        $hits = $this->search->search('headcount expansion');

        self::assertContains($hits[0]->id, ['ast_2111', 'ast_2001'], 'the two workforce planning assets are the answer');

        foreach ($hits as $hit) {
            self::assertStringNotContainsStringIgnoringCase('headcount', $hit->description);
            self::assertStringNotContainsStringIgnoringCase('expansion', $hit->description);
        }
    }

    public function testItSeparatesNearDuplicatesThatDifferInOneWord(): void
    {
        $ranking = $this->ranking($this->search->search('second quarter revenue'));

        self::assertSame('ast_1043', array_key_first($ranking), 'the second quarter breakdown should come first');

        foreach (['ast_1042', 'ast_1044', 'ast_1045'] as $otherQuarter) {
            self::assertArrayHasKey($otherQuarter, $ranking, 'the other quarters should still be retrieved');
            self::assertLessThan($ranking['ast_1043'], $ranking[$otherQuarter], 'but below the quarter that was asked for');
        }
    }

    public function testAPhraseResolvesAWordThatMeansDifferentThingsPerDomain(): void
    {
        // "retention" means three different things and appears in no description
        self::assertSame('ast_2007', $this->search->search('keeping our employees from quitting')[0]->id);
        self::assertSame('ast_5010', $this->search->search('how long we keep personal data')[0]->id);
        self::assertSame('ast_4027', $this->search->search('stopping customers cancelling')[0]->id);
    }

    public function testAnAssetWithAMeaninglessNameIsFoundThroughItsDescription(): void
    {
        $hits = $this->search->search('photos from the company summer outing');

        self::assertSame('ast_2075', $hits[0]->id);
        self::assertSame('IMG_4471.jpg', $hits[0]->name);
    }

    // limitation 1
    public function testABareAcronymMissesTheDocumentThatSpellsItOut(): void
    {
        // CAC is in the filename, in the description it's spelled out ("customer acquisition cost") -> not resolved
        $ranking = $this->ranking($this->search->search('CAC'));
        self::assertArrayNotHasKey('ast_1101', $ranking, 'if this now passes, the model resolves acronyms and the README is out of date');

        $spelledOut = $this->search->search('how long until we recover the cost of winning a customer');
        self::assertSame('ast_1101', $spelledOut[0]->id, 'the same document is easy to find by concept');
    }


    // limitation 2
    public function testAnAmbiguousWordAloneMissesTheDocumentThatNeverSpellsItOut(): void
    {
        // three assets are about a pipeline, but only two descriptions use the word
        $ranking = $this->ranking($this->search->search('pipeline health'));

        self::assertArrayHasKey('ast_3014', $ranking, 'the build pipeline says "pipeline", so it is reachable');
        self::assertArrayNotHasKey('ast_4021', $ranking, 'the sales pipeline says "open opportunities by stage", and is nowhere in the ten');
    }

    // limitation 3
    public function testADescriptionCoveringSeveralSubjectsLosesToSingleSubjectOnes(): void
    {
        // ast_8831 names all three of these, in very nearly these words
        $itsOwnPhrases = [
            'churn breakdown for enterprise accounts',
            'hiring plans for the next two quarters',
            'revenue against target by region',
        ];

        foreach ($itsOwnPhrases as $phrase) {
            $ranking = $this->ranking($this->search->search($phrase));

            self::assertArrayHasKey('ast_8831', $ranking, sprintf('"%s" should at least retrieve it', $phrase));
            self::assertNotSame('ast_8831', array_key_first($ranking), sprintf('"%s" is lifted from its own description, yet a single subject document wins', $phrase));
        }
    }

    // limitation 4
    public function testAQueryWithNoAnswerStillReturnsAFullPageOfResults(): void
    {
        $hits = $this->search->search('office thermostat settings');

        self::assertCount(10, $hits, 'kNN returns k neighbours whether or not anything is near');
        self::assertGreaterThan(0.7, $hits[0]->score, 'and the score gives no hint that these are all wrong');
    }

    public function testItReturnsTenResultsBestFirst(): void
    {
        $scores = array_map(
            static fn (SearchHit $hit): float => $hit->score,
            $this->search->search('quarterly revenue against target'),
        );

        self::assertCount(10, $scores);

        $descending = $scores;
        rsort($descending);
        self::assertSame($descending, $scores);
    }

    /**
     * @param list<SearchHit> $hits
     *
     * @return array<string, float> id to score, in the order they were returned
     */
    private function ranking(array $hits): array
    {
        $ranking = [];

        foreach ($hits as $hit) {
            $ranking[$hit->id] = $hit->score;
        }

        return $ranking;
    }
}
