# Asset semantic search

Natural language search over an asset library. `GET /search?q=how are we doing on
headcount` returns the ten closest assets with their scores. It finds them even when the
description never uses the word "headcount".

Descriptions are turned into vectors by a local embedding model and stored in
Elasticsearch. A query is embedded the same way and matched against those vectors.

## Stack

| Piece           | Choice                            | Why                                                |
| --------------- | --------------------------------- |----------------------------------------------------|
| PHP runtime     | FrankenPHP 1.12.7 / PHP 8.5       | one process, no nginx plus fpm                     |
| Framework       | Symfony 8.1                       | Flex skeleton, slim, no database/ORM               |
| Vector store    | Elasticsearch 9.5.1               | `dense_vector` and kNN, official client, good docs |
| Embedding model | Ollama running `nomic-embed-text` | 768 dimensions, OpenAI compatible API              |

There is no database. An asset lives in the sample JSON file and, once indexed, in
Elasticsearch.

## Running it

```bash
docker compose up -d --wait
docker compose exec ollama ollama pull nomic-embed-text   # first run only, ~275 MB
docker compose exec app composer install                  # first run only
docker compose exec app bin/console app:index:load        # 100 sample assets, about 2s
```

Ollama is part of the compose stack, so nothing has to be installed on the host. If you
already run it there, skip the pull and put this in `.env.local`:

```
EMBEDDING_BASE_URL=http://host.docker.internal:11434/v1
```

To see the results of a query:

```bash
curl 'http://localhost:8000/search?q=how+are+we+doing+on+headcount'
```

```json
{
    "query": "how are we doing on headcount",
    "count": 10,
    "results": [
        {
            "id": "ast_2111",
            "name": "workforce_forecast.xlsx",
            "description": "Forecast of how many people each team expects to need over the next four quarters, and when they need to start.",
            "score": 0.75594306
        },
        {
            "id": "ast_2099",
            "name": "interview_scorecard.docx",
            "description": "Scorecard interviewers complete after a candidate conversation, scoring technical depth, collaboration and motivation.",
            "score": 0.745751
        }
    ]
}
```
_(there are 10 results in total, only 2 are shown here)_

No description in the sample data contains the word "headcount", and a test checks that.

More queries to try: `stopping customers cancelling`, `photos from the company summer
outing`, `second quarter revenue`, `how long we keep personal data`.

Two commands cover the lifecycle:

```bash
bin/console app:index:load              # create the index if needed, load all sample assets
bin/console app:index:load ast_2001     # load or update one asset
bin/console app:index:load --recreate   # drop the index first
bin/console app:index:delete ast_2001   # remove one asset
```

## Configuration

`.env` file:

```
EMBEDDING_BASE_URL=http://ollama:11434/v1
EMBEDDING_MODEL=nomic-embed-text
EMBEDDING_DIMENSIONS=768
ELASTICSEARCH_URL=http://elasticsearch:9200
ELASTICSEARCH_INDEX=assets
```

`EMBEDDING_DIMENSIONS` also builds the index mapping.

## Tests

```bash
docker compose exec app vendor/bin/phpunit                    # 44 tests
docker compose exec app vendor/bin/phpunit --testsuite unit   # 30 tests, nothing has to run
```

Unit tests need no services. Integration tests use a real Elasticsearch and a real model.
They write to `assets_test`, not the dev index, and skip themselves if either service is
down.

The most important ones are:

- `AssetLifecycleTest` covers create, update and delete. An updated asset is searchable by
  its new description and not its old one. A deleted asset is not searchable at all.
- `BulkIndexingTest` puts one bad document in a batch. The refusal names it, and the rest
  of the batch is still processed correctly.
- `SampleCorpusTest` loads all hundred assets and runs the queries the data was built for.
  Four of them assert a current limitation, so the test fails when the behaviour improves and
  this README gets corrected.

## How it works

```
indexing                                     searching

asset (id, name, description)                GET /search?q=natural language
        |                                            |
        v                                            v
AssetIndexer: embed the description          AssetSearch: embed the query
        |                                            |
        v                                            v
POST /v1/embeddings  ->  768 floats          POST /v1/embeddings  ->  768 floats
        |                                            |
        v                                            v
AssetIndex: upsert by asset id               AssetIndex: kNN over `embedding`
  (_bulk, in batches of 25)                    k=10, num_candidates=100
                                                     |
                                                     v
                                             top ten hits with scores
```


## The sample data

`data/assets.json` contains a hundred assets. Rules used to generate them with AI:

- five domains, about twenty assets each, overlapping at the edges (a budget mentions
  recruitment, an employment contract is both legal and HR): finance and reporting,
  people operations, product and engineering, marketing and sales, legal and compliance
- noisy names that carry no content: `Untitled_3.pptx`, `IMG_4471.jpg`, `scan0012.pdf`
- four reserved words appear in no description anywhere, so a query using them can only be
  answered on meaning: headcount, expansion, attrition, thermostat
- the same word meaning different things per domain, and only ever in a filename:
  retention is employee, customer and data retention
- near duplicates, quarterly revenue Q1 to Q4, where a query for the second quarter has to
  rank Q2 above the other three
- mixed lengths, from six words to one description covering three subjects at once

`SampleDataTest` checks these against the file.

## Design decisions

### Only the description is embedded

Embedding names like `Q3_deck_FINAL_v2`, `IMG_4471`, `scan0012` would just add noise and pull
the vector away from the subject.

The name is still indexed, as `text` with a `keyword` subfield, so it stays available for
lookup/filtering/hybrid search/etc.

If names were written by people ("Q3 business review"), I would embed the name and the
description together.

### The asset id is the document id

Writes use `'id' => $asset->id`. Re-indexing an asset replaces the document instead of
adding a second one, so an update cannot leave the old description searchable.

Delete works the same way (deleting an id that is not there is not an error).

### Index refresh

A single write uses `refresh: 'wait_for'`, so the asset is searchable when the call
returns.

The whole asset file is loaded in batches (using `_bulk`), and the index is refreshed only once, at the end.

### `num_candidates`

The kNN query asks for `k` hits and explores `k * 10` candidates per shard.
More candidates = better accuracy, but more CPU time.

## Potential improvements

### Short term

- More test coverage (starting with HTTP-level tests for the search endpoint)
- Static analysis (add Psalm)
- A signal for whether there are no good results (a query with no answer still returns ten
  hits)
- An index alias, so a mapping or model change can be built alongside and swapped in

### With another week

- A much bigger asset set, 1000 or 10000
- A way to measure ranking: a set of queries with expected results, and a score to compare
  changes against
- Hybrid search to cover acronyms, exact terms, etc
- Tuning, eg: `num_candidates`, the HNSW settings, whether to embed the name together with the description
- Real ingestion (events/retries/etc) instead of a JSON file
