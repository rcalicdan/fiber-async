<?php

namespace Rcalicdan\FiberAsync\RAG;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\PostgreSQL\DB;
use Rcalicdan\FiberAsync\RAG\RAGQueryBuilder;
use Rcalicdan\FiberAsync\RAG\RAGHelper;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * RAG-specific database operations extending the base DB class.
 */
class RAGDB extends DB
{
    /**
     * Start a new RAG query builder instance for the given table.
     *
     * @param  string  $table  The table name to query.
     * @return RAGQueryBuilder RAG-optimized query builder.
     */
    public static function ragTable(string $table): RAGQueryBuilder
    {
        self::initializeIfNeeded();
        return new RAGQueryBuilder($table);
    }

    /**
     * Initialize RAG database schema.
     *
     * @param  string  $documentsTable  Documents table name.
     * @param  string  $chunksTable  Chunks table name.
     * @param  int  $vectorDimension  Vector dimension.
     * @return PromiseInterface<bool> A promise that resolves when schema is ready.
     */
    public static function initRAG(
        string $documentsTable = 'documents',
        string $chunksTable = 'document_chunks',
        int $vectorDimension = 1536
    ): PromiseInterface {
        self::initializeIfNeeded();
        return RAGHelper::initializeRAGSchema($documentsTable, $chunksTable, $vectorDimension);
    }

    /**
     * Perform semantic search across documents.
     *
     * @param  array<float>  $queryVector  Query embedding vector.
     * @param  array<string, mixed>  $options  Search options.
     * @param  string  $table  Table to search in.
     * @return PromiseInterface<array<int, array<string, mixed>>> Search results.
     */
    public static function semanticSearch(
        array $queryVector,
        array $options = [],
        string $table = 'document_chunks'
    ): PromiseInterface {
        self::initializeIfNeeded();
        return self::ragTable($table)->performSemanticSearch(
            $queryVector,
            $options['filters'] ?? [],
            $options['vector_column'] ?? null,
            $options['threshold'] ?? null,
            $options['limit'] ?? null
        );
    }

    /**
     * Perform hybrid search combining text and vector similarity.
     *
     * @param  string  $textQuery  Text query.
     * @param  array<float>  $queryVector  Query embedding vector.
     * @param  array<string, mixed>  $options  Search options.
     * @param  string  $table  Table to search in.
     * @return PromiseInterface<array<int, array<string, mixed>>> Search results.
     */
    public static function hybridSearch(
        string $textQuery,
        array $queryVector,
        array $options = [],
        string $table = 'document_chunks'
    ): PromiseInterface {
        self::initializeIfNeeded();
        return self::ragTable($table)->performHybridSearch(
            $textQuery,
            $queryVector,
            $options['text_column'] ?? null,
            $options['vector_column'] ?? null,
            $options['text_weight'] ?? 0.3,
            $options['vector_weight'] ?? 0.7,
            $options['limit'] ?? null
        );
    }

    /**
     * Comprehensive RAG query with advanced features.
     *
     * @param  string  $query  The search query.
     * @param  array<float>  $queryVector  Query embedding vector.
     * @param  array<string, mixed>  $options  Advanced search options.
     * @return PromiseInterface<array<string, mixed>> Comprehensive search results.
     */
    public static function ragQuery(
        string $query,
        array $queryVector,
        array $options = []
    ): PromiseInterface {
        self::initializeIfNeeded();
        return RAGHelper::comprehensiveRAGQuery($query, $queryVector, $options);
    }

    /**
     * Ingest document with automatic processing.
     *
     * @param  array<string, mixed>  $documentData  Document data.
     * @param  callable  $embeddingGenerator  Embedding generation function.
     * @param  array<string, mixed>  $options  Processing options.
     * @return PromiseInterface<int> Document ID.
     */
    public static function ingestDocument(
        array $documentData,
        callable $embeddingGenerator,
        array $options = []
    ): PromiseInterface {
        self::initializeIfNeeded();
        return RAGHelper::ingestDocument(
            $documentData,
            $embeddingGenerator,
            $options['chunk_size'] ?? 1000,
            $options['chunk_overlap'] ?? 200,
            $options['documents_table'] ?? 'documents',
            $options['chunks_table'] ?? 'document_chunks'
        );
    }

    /**
     * Optimize RAG performance.
     *
     * @param  string  $table  Table to optimize.
     * @return PromiseInterface<array<string, mixed>> Optimization report.
     */
    public static function optimizeRAG(string $table = 'document_chunks'): PromiseInterface
    {
        self::initializeIfNeeded();
        return RAGHelper::optimizeRAGPerformance($table);
    }

    /**
     * Generate embeddings report.
     *
     * @param  string  $chunksTable  Chunks table name.
     * @param  string  $documentsTable  Documents table name.
     * @return PromiseInterface<array<string, mixed>> Embeddings report.
     */
    public static function embeddingsReport(
        string $chunksTable = 'document_chunks',
        string $documentsTable = 'documents'
    ): PromiseInterface {
        self::initializeIfNeeded();
        return RAGHelper::generateEmbeddingsReport($chunksTable, $documentsTable);
    }

    /**
     * Create optimized vector indexes for RAG operations.
     *
     * @param  string  $table  The table name.
     * @param  string  $vectorColumn  The vector column name.
     * @param  array<string, mixed>  $options  Index options.
     * @return PromiseInterface<bool> Success status.
     */
    public static function createRAGIndexes(
        string $table = 'document_chunks',
        string $vectorColumn = 'embedding',
        array $options = []
    ): PromiseInterface {
        self::initializeIfNeeded();

        $defaultOptions = [
            'hnsw_m' => 16,
            'hnsw_ef_construction' => 64,
            'create_multiple_indexes' => true
        ];

        $config = array_merge($defaultOptions, $options);

        return Async::async(function () use ($table, $vectorColumn, $config): bool {
            $ragTable = self::ragTable($table);

            // Create HNSW index for cosine similarity (most common for embeddings)
            await($ragTable->createVectorIndex(
                'hnsw',
                'vector_cosine_ops',
                ['m' => $config['hnsw_m'], 'ef_construction' => $config['hnsw_ef_construction']],
                $vectorColumn
            ));

            if ($config['create_multiple_indexes']) {
                // Create additional indexes for different distance metrics
                await($ragTable->createVectorIndex(
                    'hnsw',
                    'vector_l2_ops',
                    ['m' => $config['hnsw_m'], 'ef_construction' => $config['hnsw_ef_construction']],
                    $vectorColumn
                ));

                await($ragTable->createVectorIndex(
                    'hnsw',
                    'vector_ip_ops',
                    ['m' => $config['hnsw_m'], 'ef_construction' => $config['hnsw_ef_construction']],
                    $vectorColumn
                ));
            }

            return true;
        })();
    }
}
