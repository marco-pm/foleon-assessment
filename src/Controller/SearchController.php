<?php

declare(strict_types=1);

namespace App\Controller;

use App\Embedding\EmbeddingFailedException;
use App\Search\AssetSearch;
use App\Search\SearchQueryRequest;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    public function __construct(
        private readonly AssetSearch $search,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'], format: 'json')]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        SearchQueryRequest $query,
    ): JsonResponse
    {
        try {
            $hits = $this->search->search($query->q);
        } catch (EmbeddingFailedException|ElasticsearchException|TransportException $e) {
            return $this->json(
                ['error' => 'Search is unavailable.', 'detail' => $e->getMessage()],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->json([
            'query' => $query->q,
            'count' => count($hits),
            'results' => $hits,
        ]);
    }
}
