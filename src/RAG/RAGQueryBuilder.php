<?php

namespace Rcalicdan\FiberAsync\RAG;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncPostgreSQL;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * RAG-optimized PostgreSQL query builder with async execution methods.
 */
class RAGQueryBuilder extends RAGQueryBuilderBase
{
    use StandardQueryExecutionTrait;

    /**
     * Create a new RAGQueryBuilder instance.
     *
     * @param  string  $table  The table name to query.
     */
    final public function __construct(string $table = '')
    {
        if ($table !== '') {
            $this->table = $table;
        }
    }

    /**
     * Insert document with embedding vector.
     *
     * @param  array<string, mixed>  $data  The document data.
     * @param  array<float>  $embedding  The embedding vector.
     * @param  string|null  $embeddingColumn  The embedding column name.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function insertWithEmbedding(array $data, array $embedding, ?string $embeddingColumn = null): PromiseInterface
    {
        $embeddingColumn ??= $this->ragConfig['default_vector_column'];

        if (empty($embedding)) {
            return Promise::resolve(0);
        }

        $data[$embeddingColumn] = '['.implode(',', $embedding).']';

        return $this->insert($data);
    }

    /**
     * Insert document with embedding and return the ID.
     *
     * @param  array<string, mixed>  $data  The document data.
     * @param  array<float>  $embedding  The embedding vector.
     * @param  string  $idColumn  The ID column name.
     * @param  string|null  $embeddingColumn  The embedding column name.
     * @return PromiseInterface<mixed> A promise that resolves to the inserted ID.
     */
    public function insertWithEmbeddingGetId(
        array $data,
        array $embedding,
        string $idColumn = 'id',
        ?string $embeddingColumn = null
    ): PromiseInterface {
        $embeddingColumn ??= $this->ragConfig['default_vector_column'];

        if (empty($embedding)) {
            return Promise::resolve(null);
        }

        $data[$embeddingColumn] = '['.implode(',', $embedding).']';

        return $this->insertGetId($data, $idColumn);
    }

    /**
     * Batch insert documents with embeddings.
     *
     * @param  array<array{data: array<string, mixed>, embedding: array<float>}>  $documents  Documents with embeddings.
     * @param  string|null  $embeddingColumn  The embedding column name.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function insertBatchWithEmbeddings(array $documents, ?string $embeddingColumn = null): PromiseInterface
    {
        $embeddingColumn ??= $this->ragConfig['default_vector_column'];

        if (empty($documents)) {
            return Promise::resolve(0);
        }

        $batchData = [];
        foreach ($documents as $doc) {
            $data = $doc['data'];
            $embedding = $doc['embedding'];

            if (! empty($embedding)) {
                $data[$embeddingColumn] = '['.implode(',', $embedding).']';
            }

            $batchData[] = $data;
        }

        return $this->insertBatch($batchData);
    }

    /**
     * Perform semantic search and return results.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  array<string, mixed>  $filters  Additional filters.
     * @param  string|null  $vectorColumn  The vector column name.
     * @param  string|null  $metadataColumn  The metadata column name.
     * @param  float|null  $threshold  Similarity threshold.
     * @param  int|null  $limit  Number of results to return.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to search results.
     */
    public function performSemanticSearch(
        array $queryVector,
        array $filters = [],
        ?string $vectorColumn = null,
        ?string $metadataColumn = null,
        ?float $threshold = null,
        ?int $limit = null
    ): PromiseInterface {
        $metadataColumn ??= $this->ragConfig['default_metadata_column'];
        $query = $this->semanticSearch($queryVector, $filters, $vectorColumn, $metadataColumn, $threshold, $limit);

        return $query->get();
    }

    /**
     * Perform hybrid search combining text and vector similarity.
     *
     * @param  string  $textQuery  The text query.
     * @param  array<float>  $queryVector  The query vector.
     * @param  string  $textColumn  The text column name.
     * @param  string  $vectorColumn  The vector column name.
     * @param  float  $textWeight  Weight for text search.
     * @param  float  $vectorWeight  Weight for vector search.
     * @param  int|null  $limit  Number of results to return.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to search results.
     */
    public function performHybridSearch(
        string $textQuery,
        array $queryVector,
        float $textWeight = 0.3,
        float $vectorWeight = 0.7,
        ?string $textColumn = null,
        ?string $vectorColumn = null,
        ?int $limit = null
    ): PromiseInterface {
        $textColumn ??= $this->ragConfig['default_content_column'];
        $vectorColumn ??= $this->ragConfig['default_vector_column'];
        $limit ??= $this->ragConfig['default_search_limit'];

        $query = $this->hybridSearch($textColumn, $vectorColumn, $textQuery, $queryVector, $textWeight, $vectorWeight, $limit);

        return $query->get();
    }

    /**
     * Retrieve context for RAG with citation information.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  array<string, mixed>  $filters  Additional filters.
     * @param  int  $topK  Number of results to return.
     * @param  float|null  $threshold  Similarity threshold.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to context with citations.
     */
    public function retrieveContextForRAG(
        array $queryVector,
        array $filters = [],
        int $topK = 5,
        ?float $threshold = null
    ): PromiseInterface {
        $threshold ??= $this->ragConfig['default_similarity_threshold'];

        $query = $this->retrievalWithCitation($queryVector, ['title', 'source', 'url', 'author', 'created_at'], null, $topK)
            ->semanticSearch($queryVector, $filters, null, null, $threshold, $topK)
        ;

        return $query->get();
    }

    /**
     * Create vector index for performance optimization.
     *
     * @param  string  $column  The vector column name.
     * @param  string  $method  Index method ('hnsw' or 'ivfflat').
     * @param  string  $operator  Distance operator class.
     * @param  array<string, mixed>  $options  Index options.
     * @return PromiseInterface<int> A promise that resolves when index is created.
     */
    public function createVectorIndex(
        string $method = 'hnsw',
        string $operator = 'vector_cosine_ops',
        array $options = ['m' => 16, 'ef_construction' => 64],
        ?string $column = null,
    ): PromiseInterface {
        $column ??= $this->ragConfig['default_vector_column'];
        $sql = $this->buildVectorIndexQuery($column, $method, $operator, $options);

        return AsyncPostgreSQL::execute($sql, []);
    }

    /**
     * Chunk large documents for RAG processing.
     *
     * @param  string  $contentColumn  The content column name.
     * @param  int  $chunkSize  Chunk size in characters.
     * @param  int  $overlapSize  Overlap size in characters.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to document chunks.
     */
    public function chunkDocuments(
        int $chunkSize = 1000,
        int $overlapSize = 200,
        ?string $contentColumn = null,
    ): PromiseInterface {
        $contentColumn ??= $this->ragConfig['default_content_column'];
        $sql = $this->buildChunkQuery($contentColumn, $chunkSize, $overlapSize);

        return AsyncPostgreSQL::query($sql, []);
    }

    /**
     * Update embedding for existing document.
     *
     * @param  mixed  $id  The document ID.
     * @param  array<float>  $embedding  The new embedding vector.
     * @param  string  $idColumn  The ID column name.
     * @param  string|null  $embeddingColumn  The embedding column name.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function updateEmbedding(
        mixed $id,
        array $embedding,
        string $idColumn = 'id',
        ?string $embeddingColumn = null
    ): PromiseInterface {
        $embeddingColumn ??= $this->ragConfig['default_vector_column'];

        if (empty($embedding)) {
            return Promise::resolve(0);
        }

        $vectorString = '['.implode(',', $embedding).']';

        return $this->where($idColumn, $id)->update([$embeddingColumn => $vectorString]);
    }

    /**
     * Get vector statistics for the table.
     *
     * @param  string  $vectorColumn  The vector column name.
     * @return PromiseInterface<array<string, mixed>> A promise that resolves to vector statistics.
     */
    public function getVectorStatistics(?string $vectorColumn = null): PromiseInterface
    {
        $vectorColumn ??= $this->ragConfig['default_vector_column'];

        // @phpstan-ignore-next-line
        return Async::async(function () use ($vectorColumn): array {
            // Count total vectors
            $totalCount = await($this->count());

            // Get dimension info (assuming all vectors have same dimension)
            $dimensionSql = "SELECT array_length({$vectorColumn}, 1) as dimension FROM {$this->table} LIMIT 1";
            $dimensionResult = await(AsyncPostgreSQL::fetchOne($dimensionSql, []));

            // Get null vector count
            $nullCount = await($this->whereNull($vectorColumn)->count());

            return [
                'total_vectors' => $totalCount,
                'dimension' => $dimensionResult['dimension'] ?? 0,
                'null_vectors' => $nullCount,
                'valid_vectors' => $totalCount - $nullCount,
                'table' => $this->table,
                'column' => $vectorColumn,
            ];
        })();
    }

    /**
     * Analyze vector search performance with sample queries.
     *
     * @param  array<float>  $sampleVector  Sample vector for performance testing.
     * @param  string  $vectorColumn  The vector column name.
     * @return PromiseInterface<array<string, mixed>> A promise that resolves to performance analysis.
     */
    public function analyzeVectorPerformance(
        array $sampleVector,
        ?string $vectorColumn = null
    ): PromiseInterface {
        $vectorColumn ??= $this->ragConfig['default_vector_column'];

        // @phpstan-ignore-next-line
        return Async::async(function () use ($sampleVector, $vectorColumn): array {
            $vectorString = '['.implode(',', $sampleVector).']';

            // Test cosine similarity performance
            $startTime = microtime(true);

            $cosineQuery = "
            EXPLAIN (ANALYZE, BUFFERS) 
            SELECT id, ({$vectorColumn} <=> '{$vectorString}') as distance 
            FROM {$this->table} 
            WHERE {$vectorColumn} IS NOT NULL 
            ORDER BY {$vectorColumn} <=> '{$vectorString}' 
            LIMIT 10
        ";

            $cosineResult = await(AsyncPostgreSQL::query($cosineQuery, []));
            $cosineTime = microtime(true) - $startTime;

            // Test L2 distance performance
            $startTime = microtime(true);

            $l2Query = "
            EXPLAIN (ANALYZE, BUFFERS) 
            SELECT id, ({$vectorColumn} <-> '{$vectorString}') as distance 
            FROM {$this->table} 
            WHERE {$vectorColumn} IS NOT NULL 
            ORDER BY {$vectorColumn} <-> '{$vectorString}' 
            LIMIT 10
        ";

            $l2Result = await(AsyncPostgreSQL::query($l2Query, []));
            $l2Time = microtime(true) - $startTime;

            // Parse execution plans for key metrics
            $cosineMetrics = $this->parseExecutionPlan($cosineResult);
            $l2Metrics = $this->parseExecutionPlan($l2Result);

            return [
                'cosine_similarity' => [
                    'execution_time_ms' => round($cosineTime * 1000, 2),
                    'plan_metrics' => $cosineMetrics,
                ],
                'l2_distance' => [
                    'execution_time_ms' => round($l2Time * 1000, 2),
                    'plan_metrics' => $l2Metrics,
                ],
                'recommendations' => $this->generatePerformanceRecommendations($cosineMetrics, $l2Metrics),
            ];
        })();
    }

    /**
     * Parse PostgreSQL execution plan for key metrics.
     *
     * @param  array<array<string, mixed>>  $planResult  Execution plan result.
     * @return array<string, mixed> Parsed metrics.
     */
    private function parseExecutionPlan(array $planResult): array
    {
        $metrics = [
            'total_cost' => 0,
            'actual_time' => 0,
            'rows_examined' => 0,
            'index_used' => false,
            'sequential_scan' => false,
        ];

        foreach ($planResult as $row) {
            $plan = $row['QUERY PLAN'] ?? '';

            // Extract cost information
            if (preg_match('/cost=([\d.]+)\.\.([\d.]+)/', $plan, $matches)) {
                $metrics['total_cost'] = (float) $matches[2];
            }

            // Extract actual time
            if (preg_match('/actual time=([\d.]+)\.\.([\d.]+)/', $plan, $matches)) {
                $metrics['actual_time'] = (float) $matches[2];
            }

            // Extract rows
            if (preg_match('/rows=(\d+)/', $plan, $matches)) {
                $metrics['rows_examined'] = (int) $matches[1];
            }

            // Check for index usage
            if (strpos($plan, 'Index Scan') !== false || strpos($plan, 'Bitmap Index Scan') !== false) {
                $metrics['index_used'] = true;
            }

            // Check for sequential scan
            if (strpos($plan, 'Seq Scan') !== false) {
                $metrics['sequential_scan'] = true;
            }
        }

        return $metrics;
    }

    /**
     * Generate performance recommendations based on metrics.
     *
     * @param  array<string, mixed>  $cosineMetrics  Cosine similarity metrics.
     * @param  array<string, mixed>  $l2Metrics  L2 distance metrics.
     * @return array<string> Performance recommendations.
     */
    private function generatePerformanceRecommendations(array $cosineMetrics, array $l2Metrics): array
    {
        $recommendations = [];

        if ($cosineMetrics['sequential_scan'] || $l2Metrics['sequential_scan']) {
            $recommendations[] = 'Sequential scan detected - ensure vector indexes are created and being used';
        }

        if ($cosineMetrics['actual_time'] > 100 || $l2Metrics['actual_time'] > 100) {
            $recommendations[] = 'Query execution time is high - consider tuning HNSW parameters or creating additional indexes';
        }

        if (! $cosineMetrics['index_used'] && ! $l2Metrics['index_used']) {
            $recommendations[] = 'No vector indexes are being used - create HNSW or IVFFlat indexes for better performance';
        }

        if ($cosineMetrics['total_cost'] > 10000 || $l2Metrics['total_cost'] > 10000) {
            $recommendations[] = 'High query cost detected - consider increasing work_mem or shared_buffers PostgreSQL settings';
        }

        return $recommendations;
    }
}
