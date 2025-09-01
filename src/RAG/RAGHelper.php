<?php

namespace Rcalicdan\FiberAsync\RAG;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\PostgreSQL\DB;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * RAG-specific helper class for common operations.
 */
class RAGHelper
{
    /**
     * Initialize RAG-optimized database schema.
     *
     * @param  string  $documentsTable  The documents table name.
     * @param  string  $chunksTable  The chunks table name.
     * @param  int  $vectorDimension  Vector dimension.
     * @return PromiseInterface<bool> A promise that resolves when schema is created.
     */
    public static function initializeRAGSchema(
        string $documentsTable = 'documents',
        string $chunksTable = 'document_chunks',
        int $vectorDimension = 1536
    ): PromiseInterface {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($documentsTable, $chunksTable, $vectorDimension): bool {
            // Enable vector extension
            await(DB::raw('CREATE EXTENSION IF NOT EXISTS vector'));

            // Create documents table
            await(DB::raw("
              // Continue RAGHelper class
               CREATE TABLE IF NOT EXISTS {$documentsTable} (
                   id SERIAL PRIMARY KEY,
                   title TEXT NOT NULL,
                   content TEXT NOT NULL,
                   source VARCHAR(255),
                   url TEXT,
                   author VARCHAR(255),
                   metadata JSONB DEFAULT '{}',
                   content_hash VARCHAR(64),
                   content_type VARCHAR(50) DEFAULT 'text',
                   language VARCHAR(10) DEFAULT 'en',
                   created_at TIMESTAMP DEFAULT NOW(),
                   updated_at TIMESTAMP DEFAULT NOW(),
                   UNIQUE(content_hash)
               )
           "));

            // Create document chunks table
            await(DB::raw("
               CREATE TABLE IF NOT EXISTS {$chunksTable} (
                   id SERIAL PRIMARY KEY,
                   document_id INTEGER REFERENCES {$documentsTable}(id) ON DELETE CASCADE,
                   chunk_text TEXT NOT NULL,
                   chunk_index INTEGER NOT NULL,
                   embedding VECTOR({$vectorDimension}),
                   token_count INTEGER,
                   start_position INTEGER,
                   end_position INTEGER,
                   metadata JSONB DEFAULT '{}',
                   search_vector tsvector,
                   created_at TIMESTAMP DEFAULT NOW()
               )
           "));

            // Create indexes
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$documentsTable}_source ON {$documentsTable}(source)"));
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$documentsTable}_content_type ON {$documentsTable}(content_type)"));
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$documentsTable}_metadata ON {$documentsTable} USING GIN(metadata)"));
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$documentsTable}_content_hash ON {$documentsTable}(content_hash)"));

            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$chunksTable}_document_id ON {$chunksTable}(document_id)"));
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$chunksTable}_chunk_index ON {$chunksTable}(document_id, chunk_index)"));
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$chunksTable}_search ON {$chunksTable} USING GIN(search_vector)"));

            // Vector indexes (will be created when embeddings are added)
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$chunksTable}_embedding_cosine ON {$chunksTable} USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)"));
            await(DB::raw("CREATE INDEX IF NOT EXISTS idx_{$chunksTable}_embedding_l2 ON {$chunksTable} USING hnsw (embedding vector_l2_ops) WITH (m = 16, ef_construction = 64)"));

            // Create function to update search vector
            await(DB::raw("
               CREATE OR REPLACE FUNCTION update_chunk_search_vector()
               RETURNS TRIGGER AS \$\$
               BEGIN
                   NEW.search_vector := to_tsvector('english', NEW.chunk_text);
                   RETURN NEW;
               END;
               \$\$ LANGUAGE plpgsql;
           "));

            // Create trigger for search vector updates
            await(DB::raw("
               DROP TRIGGER IF EXISTS trigger_update_search_vector ON {$chunksTable};
               CREATE TRIGGER trigger_update_search_vector
                   BEFORE INSERT OR UPDATE ON {$chunksTable}
                   FOR EACH ROW EXECUTE FUNCTION update_chunk_search_vector();
           "));

            return true;
        })();
    }

    /**
     * Ingest document with automatic chunking and embedding.
     *
     * @param  array<string, mixed>  $documentData  Document data.
     * @param  callable  $embeddingGenerator  Function to generate embeddings.
     * @param  int  $chunkSize  Chunk size in characters.
     * @param  int  $chunkOverlap  Chunk overlap in characters.
     * @param  string  $documentsTable  Documents table name.
     * @param  string  $chunksTable  Chunks table name.
     * @return PromiseInterface<int> A promise that resolves to the document ID.
     */
    public static function ingestDocument(
        array $documentData,
        callable $embeddingGenerator,
        int $chunkSize = 1000,
        int $chunkOverlap = 200,
        string $documentsTable = 'documents',
        string $chunksTable = 'document_chunks'
    ): PromiseInterface {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($documentData, $embeddingGenerator, $chunkSize, $chunkOverlap, $documentsTable, $chunksTable): int {
            return await(DB::transaction(function () use ($documentData, $embeddingGenerator, $chunkSize, $chunkOverlap, $documentsTable, $chunksTable): int {
                $documentData['content_hash'] = hash('sha256', $documentData['content']);

                $documentId = await(RAGDB::ragTable($documentsTable)->insertGetId($documentData));

                $chunks = self::chunkText($documentData['content'], $chunkSize, $chunkOverlap);

                $chunkData = [];
                foreach ($chunks as $index => $chunk) {
                    $embedding = await($embeddingGenerator($chunk['text']));

                    $chunkData[] = [
                        'data' => [
                            'document_id' => $documentId,
                            'chunk_text' => $chunk['text'],
                            'chunk_index' => $index,
                            'token_count' => $chunk['token_count'],
                            'start_position' => $chunk['start_position'],
                            'end_position' => $chunk['end_position'],
                            'metadata' => json_encode([
                                'chunk_length' => strlen($chunk['text']),
                                'source' => $documentData['source'] ?? null,
                                'content_type' => $documentData['content_type'] ?? 'text',
                            ]),
                        ],
                        'embedding' => $embedding,
                    ];
                }

                await(RAGDB::ragTable($chunksTable)->insertBatchWithEmbeddings($chunkData));

                return $documentId;
            }));
        })();
    }

    /**
     * Perform comprehensive RAG query with multiple search strategies.
     *
     * @param  string  $query  The search query.
     * @param  array<float>  $queryVector  The query embedding.
     * @param  array<string, mixed>  $options  Search options.
     * @param  string  $chunksTable  Chunks table name.
     * @return PromiseInterface<array<string, mixed>> A promise that resolves to search results.
     */
    public static function comprehensiveRAGQuery(
        string $query,
        array $queryVector,
        array $options = [],
        string $chunksTable = 'document_chunks'
    ): PromiseInterface {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($query, $queryVector, $options, $chunksTable): array {
            $defaults = [
                'limit' => 10,
                'threshold' => 0.7,
                'text_weight' => 0.3,
                'vector_weight' => 0.7,
                'enable_hybrid' => true,
                'enable_temporal' => false,
                'enable_diversity' => false,
                'metadata_filters' => [],
                'content_types' => ['text'],
                'conversation_id' => null,
            ];

            $config = array_merge($defaults, $options);

            $queryBuilder = RAGDB::ragTable($chunksTable);

            if ($config['enable_hybrid'] && ! empty($query)) {
                $results = await($queryBuilder->performHybridSearch(
                    $query,
                    $queryVector,
                    'chunk_text',
                    'embedding',
                    $config['text_weight'],
                    $config['vector_weight'],
                    $config['limit']
                ));
            } else {
                $results = await($queryBuilder->performSemanticSearch(
                    $queryVector,
                    $config['metadata_filters'],
                    'embedding',
                    $config['threshold'],
                    $config['limit']
                ));
            }

            // Post-process results for diversity if enabled
            if ($config['enable_diversity']) {
                $results = self::applyDiversityFiltering($results, 0.3);
            }

            // Add document information
            foreach ($results as &$result) {
                $documentInfo = await(RAGDB::ragTable('documents')
                    ->find($result['document_id']));

                $result['document'] = $documentInfo;

                // Format for RAG usage
                $result['citation'] = [
                    'title' => $documentInfo['title'] ?? 'Unknown',
                    'source' => $documentInfo['source'] ?? 'Unknown',
                    'url' => $documentInfo['url'] ?? null,
                    'author' => $documentInfo['author'] ?? null,
                    'chunk_index' => $result['chunk_index'],
                    'similarity_score' => $result['similarity_score'] ?? $result['hybrid_score'] ?? 0,
                ];
            }

            return [
                'results' => $results,
                'query' => $query,
                'total_results' => count($results),
                'search_config' => $config,
                'timestamp' => date('c'),
            ];
        })();
    }

    /**
     * Optimize RAG performance by analyzing and tuning indexes.
     *
     * @param  string  $chunksTable  Chunks table name.
     * @return PromiseInterface<array<string, mixed>> A promise that resolves to optimization report.
     */
    public static function optimizeRAGPerformance(string $chunksTable = 'document_chunks'): PromiseInterface
    {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($chunksTable): array {
            $report = [];

            $stats = await(RAGDB::ragTable($chunksTable)->getVectorStatistics());
            $report['table_stats'] = $stats;

            $sampleVector = array_fill(0, 1536, 0.1);
            $performance = await(RAGDB::ragTable($chunksTable)->analyzeVectorPerformance($sampleVector));
            $report['performance_analysis'] = $performance;

            // Check index usage
            $indexUsage = await(DB::raw('
               SELECT 
                   schemaname,
                   tablename,
                   indexname,
                   idx_scan,
                   idx_tup_read,
                   idx_tup_fetch
               FROM pg_stat_user_indexes 
               WHERE tablename = ?
               ORDER BY idx_scan DESC
           ', [$chunksTable]));

            $report['index_usage'] = $indexUsage;

            // Recommendations
            $recommendations = [];

            if ($stats['total_vectors'] > 100000) {
                $recommendations[] = 'Consider partitioning the table by document_id for better performance';
            }

            if ($stats['null_vectors'] > $stats['total_vectors'] * 0.1) {
                $recommendations[] = 'High percentage of null vectors detected - consider cleaning up data';
            }

            // Check if HNSW parameters need tuning
            if ($stats['total_vectors'] > 1000000) {
                $recommendations[] = 'Consider increasing HNSW m parameter to 32 for large datasets';
                $recommendations[] = 'Increase ef_construction to 128 for better accuracy on large datasets';
            }

            $report['recommendations'] = $recommendations;

            return $report;
        })();
    }

    /**
     * Clean up duplicate or low-quality chunks.
     *
     * @param  float  $similarityThreshold  Threshold for considering chunks as duplicates.
     * @param  int  $minTokenCount  Minimum token count for valid chunks.
     * @param  string  $chunksTable  Chunks table name.
     * @return PromiseInterface<array<string, int>> A promise that resolves to cleanup statistics.
     */
    public static function cleanupChunks(
        float $similarityThreshold = 0.95,
        int $minTokenCount = 10,
        string $chunksTable = 'document_chunks'
    ): PromiseInterface {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($minTokenCount, $chunksTable): array {
            return await(DB::transaction(function () use ($minTokenCount, $chunksTable): array {
                $stats = ['duplicates_removed' => 0, 'low_quality_removed' => 0];

                // Remove chunks with too few tokens
                $lowQualityCount = await(RAGDB::ragTable($chunksTable)
                    ->where('token_count', '<', $minTokenCount)
                    ->delete());

                $stats['low_quality_removed'] = $lowQualityCount;

                // Remove empty or whitespace-only chunks
                $emptyCount = await(DB::raw(
                    "DELETE FROM {$chunksTable} WHERE trim(chunk_text) = '' OR chunk_text IS NULL"
                ));

                $stats['empty_removed'] = $emptyCount;

                return $stats;
            }));
        })();
    }

    /**
     * Generate embeddings report for monitoring.
     *
     * @param  string  $chunksTable  Chunks table name.
     * @param  string  $documentsTable  Documents table name.
     * @return PromiseInterface<array<string, mixed>> A promise that resolves to embeddings report.
     */
    public static function generateEmbeddingsReport(
        string $chunksTable = 'document_chunks',
        string $documentsTable = 'documents'
    ): PromiseInterface {
        // @phpstan-ignore-next-line
        return Async::async(function () use ($chunksTable, $documentsTable): array {
            $docStats = await(DB::raw("
               SELECT 
                   COUNT(*) as total_documents,
                   COUNT(DISTINCT source) as unique_sources,
                   COUNT(DISTINCT content_type) as content_types,
                   AVG(LENGTH(content)) as avg_content_length
               FROM {$documentsTable}
           "));

            // Chunk statistics
            $chunkStats = await(DB::raw("
               SELECT 
                   COUNT(*) as total_chunks,
                   COUNT(embedding) as chunks_with_embeddings,
                   AVG(token_count) as avg_token_count,
                   MIN(token_count) as min_token_count,
                   MAX(token_count) as max_token_count,
                   AVG(LENGTH(chunk_text)) as avg_chunk_length
               FROM {$chunksTable}
           "));

            // Source distribution
            $sourceDistribution = await(DB::raw("
               SELECT 
                   d.source,
                   COUNT(c.id) as chunk_count,
                   COUNT(CASE WHEN c.embedding IS NOT NULL THEN 1 END) as embedded_chunks
               FROM {$documentsTable} d
               LEFT JOIN {$chunksTable} c ON d.id = c.document_id
               GROUP BY d.source
               ORDER BY chunk_count DESC
           "));

            return [
                'document_stats' => $docStats[0] ?? [],
                'chunk_stats' => $chunkStats[0] ?? [],
                'source_distribution' => $sourceDistribution,
                'embedding_coverage' => [
                    'percentage' => ($chunkStats[0]['chunks_with_embeddings'] ?? 0) / ($chunkStats[0]['total_chunks'] ?? 1) * 100,
                    'missing_embeddings' => ($chunkStats[0]['total_chunks'] ?? 0) - ($chunkStats[0]['chunks_with_embeddings'] ?? 0),
                ],
                'generated_at' => date('c'),
            ];
        })();
    }

    /**
     * Chunk text with overlap for RAG processing.
     *
     * @param  string  $text  The text to chunk.
     * @param  int  $chunkSize  Chunk size in characters.
     * @param  int  $overlapSize  Overlap size in characters.
     * @return array<array<string, mixed>> Array of text chunks.
     */
    private static function chunkText(string $text, int $chunkSize = 1000, int $overlapSize = 200): array
    {
        $chunks = [];
        $textLength = strlen($text);
        $position = 0;
        $chunkIndex = 0;

        while ($position < $textLength) {
            $remainingLength = $textLength - $position;
            $currentChunkSize = min($chunkSize, $remainingLength);
            $chunk = substr($text, $position, $currentChunkSize);

            // Don't break words unless we're at the very end
            if ($position + $chunkSize < $textLength) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false && $lastSpace > $chunkSize * 0.8) {
                    $chunk = substr($chunk, 0, $lastSpace);
                    $actualChunkSize = $lastSpace;
                } else {
                    $actualChunkSize = $currentChunkSize;
                }
            } else {
                $actualChunkSize = $currentChunkSize;
            }

            $chunkText = trim($chunk);
            if (! empty($chunkText)) {
                $chunks[] = [
                    'text' => $chunkText,
                    'chunk_index' => $chunkIndex++,
                    'start_position' => $position,
                    'end_position' => $position + $actualChunkSize - 1,
                    'token_count' => self::estimateTokenCount($chunkText),
                ];
            }

            // Move position forward, accounting for overlap
            $position += max(1, $actualChunkSize - $overlapSize);
        }

        return $chunks;
    }

    /**
     * Estimate token count for text.
     *
     * @param  string  $text  The text to analyze.
     * @return int Estimated token count.
     */
    private static function estimateTokenCount(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    /**
     * Apply diversity filtering to search results.
     *
     * @param  array<array<string, mixed>>  $results  Search results.
     * @param  float  $diversityThreshold  Diversity threshold.
     * @return array<array<string, mixed>> Filtered results.
     */
    private static function applyDiversityFiltering(array $results, float $diversityThreshold = 0.3): array
    {
        if (empty($results)) {
            return $results;
        }

        $filtered = [$results[0]]; // Always keep the most relevant result

        foreach (array_slice($results, 1) as $candidate) {
            $shouldInclude = true;

            foreach ($filtered as $selected) {
                // Simple diversity check based on content similarity
                $similarity = self::calculateTextSimilarity(
                    $candidate['chunk_text'] ?? '',
                    $selected['chunk_text'] ?? ''
                );

                if ($similarity > (1 - $diversityThreshold)) {
                    $shouldInclude = false;

                    break;
                }
            }

            if ($shouldInclude) {
                $filtered[] = $candidate;
            }
        }

        return $filtered;
    }

    /**
     * Calculate simple text similarity for diversity filtering.
     *
     * @param  string  $text1  First text.
     * @param  string  $text2  Second text.
     * @return float Similarity score (0-1).
     */
    private static function calculateTextSimilarity(string $text1, string $text2): float
    {
        if (empty($text1) || empty($text2)) {
            return 0.0;
        }

        $words1 = array_unique(str_word_count(strtolower($text1), 1));
        $words2 = array_unique(str_word_count(strtolower($text2), 1));

        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
