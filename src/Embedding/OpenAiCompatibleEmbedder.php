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
        try {
            $payload = $this->httpClient->request('POST', $this->endpoint, [
                'json' => [
                    'model' => $this->model,
                    'input' => $text,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ])->toArray();
        } catch (DecodingExceptionInterface) {
            // the server answered, it just did not answer with JSON
            throw EmbeddingFailedException::unexpectedResponse($this->model, 'the response body is not JSON');
        } catch (HttpClientException $e) {
            throw EmbeddingFailedException::unreachable($this->endpoint, $this->model, $e);
        }

        return $this->vectorFrom($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<float>
     *
     * @throws EmbeddingFailedException
     */
    private function vectorFrom(array $payload): array
    {
        $vector = $payload['data'][0]['embedding'] ?? null;

        if (!is_array($vector) || [] === $vector) {
            throw EmbeddingFailedException::unexpectedResponse($this->model, 'no vector under data[0].embedding');
        }

        foreach ($vector as $component) {
            if (!is_int($component) && !is_float($component)) {
                throw EmbeddingFailedException::unexpectedResponse($this->model, 'the vector contains a non-numeric component');
            }
        }

        return array_map(static fn (int|float $component): float => (float) $component, array_values($vector));
    }
}
