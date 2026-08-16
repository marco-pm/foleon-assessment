<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\Embedding\EmbeddingFailedException;
use App\Embedding\OpenAiCompatibleEmbedder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleEmbedderTest extends TestCase
{
    private const string MODEL = 'nomic-embed-text';

    public function testItPostsTheTextToTheEmbeddingsEndpoint(): void
    {
        $response = self::jsonResponse(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]);
        $embedder = new OpenAiCompatibleEmbedder(new MockHttpClient($response), 'http://model:11434/v1', self::MODEL);

        $embedder->embed('a quarterly business review deck');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://model:11434/v1/embeddings', $response->getRequestUrl());
        self::assertSame(
            ['model' => self::MODEL, 'input' => ['a quarterly business review deck']],
            json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testItEmbedsAWholeBatchInOneRequest(): void
    {
        $response = self::jsonResponse(['data' => [
            ['index' => 0, 'embedding' => [0.1]],
            ['index' => 1, 'embedding' => [0.2]],
            ['index' => 2, 'embedding' => [0.3]],
        ]]);
        $embedder = new OpenAiCompatibleEmbedder(new MockHttpClient($response), 'http://model:11434/v1', self::MODEL);

        $vectors = $embedder->embedAll(['first', 'second', 'third']);

        self::assertSame([[0.1], [0.2], [0.3]], $vectors);
        self::assertSame(
            ['model' => self::MODEL, 'input' => ['first', 'second', 'third']],
            json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testItPutsABatchBackInTheOrderTheTextsCameIn(): void
    {
        // the contract allows any order, each vector carrying the position it belongs to
        $client = new MockHttpClient(self::jsonResponse(['data' => [
            ['index' => 2, 'embedding' => [0.3]],
            ['index' => 0, 'embedding' => [0.1]],
            ['index' => 1, 'embedding' => [0.2]],
        ]]));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        self::assertSame([[0.1], [0.2], [0.3]], $embedder->embedAll(['first', 'second', 'third']));
    }

    public function testItFailsWhenTheBatchComesBackShort(): void
    {
        $client = new MockHttpClient(self::jsonResponse(['data' => [
            ['index' => 0, 'embedding' => [0.1]],
            ['index' => 2, 'embedding' => [0.3]],
        ]]));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        $this->expectException(EmbeddingFailedException::class);
        $this->expectExceptionMessageMatches('/data\[1\]\.embedding/');

        $embedder->embedAll(['first', 'second', 'third']);
    }

    public function testAnEmptyBatchNeverReachesTheModel(): void
    {
        $client = new MockHttpClient(static fn (): never => self::fail('an empty batch must not be sent'));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        self::assertSame([], $embedder->embedAll([]));
    }

    public function testItReturnsTheVectorAsFloats(): void
    {
        // a model may serialise a round number as an int, the vector must stay a list<float>
        $response = self::jsonResponse(['data' => [['embedding' => [0.5, 1, -2]]]]);
        $embedder = new OpenAiCompatibleEmbedder(new MockHttpClient($response), 'http://model:11434/v1', self::MODEL);

        $vector = $embedder->embed('anything');

        self::assertSame([0.5, 1.0, -2.0], $vector);
    }

    public function testItToleratesATrailingSlashInTheBaseUrl(): void
    {
        $response = self::jsonResponse(['data' => [['embedding' => [0.1]]]]);
        $embedder = new OpenAiCompatibleEmbedder(new MockHttpClient($response), 'http://model:11434/v1/', self::MODEL);

        $embedder->embed('anything');

        self::assertSame('http://model:11434/v1/embeddings', $response->getRequestUrl());
    }

    public function testItFailsWhenTheServerCannotBeReached(): void
    {
        $client = new MockHttpClient(static fn (): never => throw new TransportException('Connection refused'));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        $this->expectException(EmbeddingFailedException::class);
        $this->expectExceptionMessageMatches('/Could not reach/');

        $embedder->embed('anything');
    }

    public function testItFailsOnAServerError(): void
    {
        $client = new MockHttpClient(self::jsonResponse(['error' => 'model not found'], 404));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        $this->expectException(EmbeddingFailedException::class);

        $embedder->embed('anything');
    }

    public function testItFailsWhenTheResponseIsNotJson(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>gateway</html>', [
            'response_headers' => ['content-type' => 'text/html'],
        ]));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        $this->expectException(EmbeddingFailedException::class);
        $this->expectExceptionMessageMatches('/not JSON/');

        $embedder->embed('anything');
    }

    public function testItFailsWhenThePayloadCarriesNoVector(): void
    {
        $client = new MockHttpClient(self::jsonResponse(['data' => []]));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        $this->expectException(EmbeddingFailedException::class);
        $this->expectExceptionMessageMatches('/data\[0\]\.embedding/');

        $embedder->embed('anything');
    }

    public function testItFailsWhenTheVectorIsNotNumeric(): void
    {
        $client = new MockHttpClient(self::jsonResponse(['data' => [['embedding' => [0.1, 'NaN']]]]));
        $embedder = new OpenAiCompatibleEmbedder($client, 'http://model:11434/v1', self::MODEL);

        $this->expectException(EmbeddingFailedException::class);
        $this->expectExceptionMessageMatches('/non-numeric/');

        $embedder->embed('anything');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
