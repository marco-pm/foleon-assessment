<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Make sure the test data is consistent with the README.
 */
final class SampleDataTest extends TestCase
{
    private const string PATH = __DIR__ . '/../../../data/assets.json';

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedQueryWords(): iterable
    {
        yield 'headcount' => ['headcount'];
        yield 'expansion' => ['expansion'];
        yield 'attrition' => ['attrition'];
        yield 'thermostat' => ['thermostat'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousWordsThatOnlyLiveInFilenames(): iterable
    {
        yield 'retention' => ['retention'];
        yield 'onboarding' => ['onboarding'];
    }

    public function testTheSampleFileHoldsAHundredDistinctAssets(): void
    {
        $assets = $this->assets();

        self::assertCount(100, $assets);
        self::assertCount(100, array_unique(array_column($assets, 'id')));
        self::assertCount(100, array_unique(array_column($assets, 'name')));
    }

    #[DataProvider('reservedQueryWords')]
    public function testAReservedQueryWordAppearsInNoDescription(string $word): void
    {
        self::assertStringNotContainsStringIgnoringCase($word, $this->descriptions());
    }

    #[DataProvider('ambiguousWordsThatOnlyLiveInFilenames')]
    public function testAnAmbiguousWordAppearsInFilenamesButInNoDescription(string $word): void
    {
        self::assertStringContainsStringIgnoringCase($word, $this->names(), 'the trap needs the word in a filename');
        self::assertStringNotContainsStringIgnoringCase($word, $this->descriptions(), 'the trap only works while the description avoids the word');
    }

    public function testDescriptionsVaryInLength(): void
    {
        $lengths = array_map(
            static fn (array $asset): int => str_word_count($asset['description']),
            $this->assets(),
        );

        self::assertLessThanOrEqual(8, min($lengths), 'some descriptions should be barely a sentence');
        self::assertGreaterThanOrEqual(25, max($lengths), 'some should be long enough to cover several topics at once');
    }

    public function testSomeAssetsCarryAnUninformativeName(): void
    {
        $uninformative = array_filter(
            $this->assets(),
            static fn (array $asset): bool => 1 === preg_match('/^(Untitled|IMG|scan)[_0-9]/', $asset['name']),
        );

        self::assertGreaterThanOrEqual(10, count($uninformative), 'the case for embedding the description rests on these');
    }

    /**
     * @return list<array{id: string, name: string, description: string}>
     */
    private function assets(): array
    {
        return json_decode((string) file_get_contents(self::PATH), true, flags: JSON_THROW_ON_ERROR);
    }

    private function descriptions(): string
    {
        return implode(' ', array_column($this->assets(), 'description'));
    }

    private function names(): string
    {
        return implode(' ', array_column($this->assets(), 'name'));
    }
}
