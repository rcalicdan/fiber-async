<?php

namespace Rcalicdan\FiberAsync\QueryBuilder\RAG;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncPostgreSQL;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\RAG\RAGQueryBuilderBase;
use Rcalicdan\FiberAsync\RAG\StandardQueryExecutionTrait;

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
     * @param  string  $embeddingColumn  The embedding column name.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function insertWithEmbedding(array $data, array $embedding, ?string $embeddingColumn = null): PromiseInterface
    {
        $embeddingColumn = $embeddingColumn ?? $this->ragConfig['default_vector_column'];
        
        if (empty($embedding)) {
            return Promise::resolve(0);
        }

        $data[$embeddingColumn] = '[' . implode(',', $embedding) . ']';
        return $this->insert($data);
    }

    /**
     * Insert document with embedding and return the ID.
     *
     * @param  array<string, mixed>  $data  The document data.
     * @param  array<float>  $embedding  The embedding vector.
     * @param  string  $embeddingColumn  The embedding column name.
     * @param  string  $idColumn  The ID column name.
     * @return PromiseInterface<mixed> A promise that resolves to the inserted ID.
     */
    public function insertWithEmbeddingGetId(
        array $data,
        array $embedding,
        ?string $embeddingColumn = null,
        string $idColumn = 'id'
    ): PromiseInterface {
        $embeddingColumn = $embeddingColumn ?? $this->ragConfig['default_vector_column'];
        
        if (empty($embedding)) {
            return Promise::resolve(null);
        }

        $data[$embeddingColumn] = '[' . implode(',', $embedding) . ']';
        return $this->insertGetId($data, $idColumn);
    }

    /**
     * Batch insert documents with embeddings.
     *
     * @param  array<array{data: array<string, mixed>, embedding: array<float>}>  $documents  Documents with embeddings.
     * @param  string  $embeddingColumn  The embedding column name.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function insertBatchWithEmbeddings(array $documents, ?string $embeddingColumn = null): PromiseInterface
    {
        $embeddingColumn = $embeddingColumn ?? $this->ragConfig['default_vector_column'];
        
        if (empty($documents)) {
            return Promise::resolve(0);
        }

        $batchData = [];
        foreach ($documents as $doc) {
            $data = $doc['data'];
            $embedding = $doc['embedding'];
            
            if (!empty($embedding)) {
                $data[$embeddingColumn] = '[' . implode(',', $embedding) . ']';
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
     * @param  string  $vectorColumn  The vector column name.
     * @param  float  $threshold  Similarity threshold.
     * @param  int  $limit  Number of results to return.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to search results.
     */
    public function performSemanticSearch(
        array $queryVector,
        array $filters = [],
        ?string $vectorColumn = null,
        ?float $threshold = null,
        ?int $limit = null
    ): PromiseInterface {
        $query = $this->semanticSearch($queryVector, $filters, $vectorColumn, $this->ragConfig['default_metadata_column'], $threshold, $limit);
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
     * @param  int  $limit  Number of results to return.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to search results.
     */
    public function performHybridSearch(
        string $textQuery,
        array $queryVector,
        ?string $textColumn = null,
        ?string $vectorColumn = null,
        float $textWeight = 0.3,
        float $vectorWeight = 0.7,
        ?int $limit = null
    ): PromiseInterface {
        $textColumn = $textColumn ?? $this->ragConfig['default_content_column'];
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        $limit = $limit ?? $this->ragConfig['default_search_limit'];
        
        $query = $this->hybridSearch($textColumn, $vectorColumn, $textQuery, $queryVector, $textWeight, $vectorWeight, $limit);
        return $query->get();
    }

    /**
     * Retrieve context for RAG with citation information.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  array<string, mixed>  $filters  Additional filters.
     * @param  int  $topK  Number of results to return.
     * @param  float  $threshold  Similarity threshold.
     * @return PromiseInterface<array<int, array<string, mixed>>> A promise that resolves to context with citations.
     */
    public function retrieveContextForRAG(
        array $queryVector,
        array $filters = [],
        int $topK = 5,
        ?float $threshold = null
    ): PromiseInterface {
        $threshold = $threshold ?? $this->ragConfig['default_similarity_threshold'];
        
        $query = $this->retrievalWithCitation($queryVector, ['title', 'source', 'url', 'author', 'created_at'], null, $topK)
                      ->semanticSearch($queryVector, $filters, null, null, $threshold, $topK);
        
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
        ?string $column = null,
        string $method = 'hnsw',
        string $operator = 'vector_cosine_ops',
        array $options = ['m' => 16, 'ef_construction' => 64]
    ): PromiseInterface {
        $column = $column ?? $this->ragConfig['default_vector_column'];
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
        ?string $contentColumn = null,
        int $chunkSize = 1000,
        int $overlapSize = 200
    ): PromiseInterface {
        $contentColumn = $contentColumn ?? $this->ragConfig['default_content_column'];
        $sql = $this->buildChunkQuery($contentColumn, $chunkSize, $overlapSize);
        return AsyncPostgreSQL::query($sql, []);
    }

    /**
     * Update embedding for existing document.
     *
     * @param  mixed  $id  The document ID.
     * @param  array<float>  $embedding  The new embedding vector.
     * @param  string  $embeddingColumn  The embedding column name.
     * @param  string  $idColumn  The ID column name.
     * @return PromiseInterface<int> A promise that resolves to the number of affected rows.
     */
    public function updateEmbedding(
        mixed $id,
        array $embedding,
        ?string $embeddingColumn = null,
        string $idColumn = 'id'
    ): PromiseInterface {
        $embeddingColumn = $embeddingColumn ?? $this->ragConfig['default_vector_column'];
        
        if (empty($embedding)) {
            return Promise::resolve(0);
        }

        $vectorString = '[' . implode(',', $embedding) . ']';
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
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        
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
                'column' => $vectorColumn
            ];
        })();
    }
}