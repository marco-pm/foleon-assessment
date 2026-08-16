<?php

declare(strict_types=1);

namespace App\Embedding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCompatibleEmbedder implements EmbedderInterface
{
    private const string EMBEDDINGS_PATH = '/embeddings';
    private const int TIMEOUT_SECONDS = 30;

    private readonly string $endpoint;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(EMBEDDING_BASE_URL)%')]
        string $baseUrl,
        #[Autowire('%env(EMBEDDING_MODEL)%')]
        private readonly string $model,
    ) {
        $this->endpoint = rtrim($baseUrl, '/') . self::EMBEDDINGS_PATH;
    }

    public function embed(string $text): array
    {
        return $this->embedAll([$text])[0];
    }

    public function embedAll(array $texts): array
    {
        if ([] === $texts) {
            return [];
        }

        try {
            $payload = $this->httpClient->request('POST', $this->endpoint, [
                'json' => [
                    'model' => $this->model,
                    'input' => $texts,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ])->toArray();
        } catch (DecodingExceptionInterface) {
            // the server answered, it just did not answer with JSON
            throw EmbeddingFailedException::unexpectedResponse($this->model, 'the response body is not JSON');
        } catch (HttpClientException $e) {
            throw EmbeddingFailedException::unreachable($this->endpoint, $this->model, $e);
        }

        return $this->vectorsFrom($payload, count($texts));
    }

    /**
     * The OpenAI contract allows the vectors to come back in any order, each carrying
     * the position of the input it belongs to, so they are put back in order here.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<list<float>>
     *
     * @throws EmbeddingFailedException
     */
    private function vectorsFrom(array $payload, int $expected): array
    {
        $data = $payload['data'] ?? null;

        if (!is_array($data)) {
            throw EmbeddingFailedException::unexpectedResponse($this->model, 'no vectors under data');
        }

        $byPosition = [];

        foreach (array_values($data) as $position => $item) {
            if (!is_array($item)) {
                continue;
            }

            $byPosition[is_int($item['index'] ?? null) ? $item['index'] : $position] = $item['embedding'] ?? null;
        }

        $vectors = [];

        for ($position = 0; $position < $expected; ++$position) {
            $vectors[] = $this->vectorAt($byPosition[$position] ?? null, $position);
        }

        return $vectors;
    }

    /**
     * @return list<float>
     *
     * @throws EmbeddingFailedException
     */
    private function vectorAt(mixed $vector, int $position): array
    {
        if (!is_array($vector) || [] === $vector) {
            throw EmbeddingFailedException::unexpectedResponse($this->model, sprintf('no vector under data[%d].embedding', $position));
        }

        foreach ($vector as $component) {
            if (!is_int($component) && !is_float($component)) {
                throw EmbeddingFailedException::unexpectedResponse($this->model, 'the vector contains a non-numeric component');
            }
        }

        return array_map(static fn (int|float $component): float => (float) $component, array_values($vector));
    }
}
