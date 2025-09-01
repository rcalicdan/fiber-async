<?php

namespace Rcalicdan\FiberAsync\RAG;

use Rcalicdan\FiberAsync\QueryBuilder\PostgresQueryBuilderBase;

/**
 * RAG-specific PostgreSQL query builder base with vector and semantic search capabilities.
 * Extends the standard query builder with RAG-optimized methods.
 */
abstract class RAGQueryBuilderBase extends PostgresQueryBuilderBase
{
    /**
     * @var array<string, mixed> RAG-specific configuration
     */
    protected array $ragConfig = [
        'default_vector_column' => 'embedding',
        'default_content_column' => 'content',
        'default_metadata_column' => 'metadata',
        'default_similarity_threshold' => 0.7,
        'default_search_limit' => 10,
        'vector_dimension' => 1536,
        'text_search_config' => 'english'
    ];

    /**
     * Set RAG configuration options.
     *
     * @param  array<string, mixed>  $config  Configuration array.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function withRAGConfig(array $config): static
    {
        $instance = clone $this;
        $instance->ragConfig = array_merge($this->ragConfig, $config);
        return $instance;
    }

    /**
     * Vector similarity search using cosine distance.
     *
     * @param  string  $column  The vector column name.
     * @param  array<float>  $queryVector  The query vector.
     * @param  int  $limit  Number of results to return.
     * @param  float  $threshold  Similarity threshold (0-1).
     * @return static Returns a new query builder instance for method chaining.
     */
    public function vectorSimilarity(string $column, array $queryVector, int $limit = 10, float $threshold = 0.7): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $vectorString = '[' . implode(',', $queryVector) . ']';
        
        // Add similarity score to select
        $instance->select[] = "(1 - ({$column} <=> {$placeholder})) as similarity_score";
        
        // Filter by threshold
        $thresholdPlaceholder = $instance->getPlaceholder();
        $instance->where[] = "(1 - ({$column} <=> {$placeholder})) >= {$thresholdPlaceholder}";
        
        $instance->bindings['where'][] = $vectorString;
        $instance->bindings['where'][] = $vectorString;
        $instance->bindings['where'][] = $threshold;
        
        return $instance->orderBy("{$column} <=> '{$vectorString}'")->limit($limit);
    }

    /**
     * Vector distance search with multiple distance metrics.
     *
     * @param  string  $column  The vector column name.
     * @param  array<float>  $queryVector  The query vector.
     * @param  string  $metric  Distance metric ('cosine', 'l2', 'inner_product').
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function vectorDistance(string $column, array $queryVector, string $metric = 'cosine', int $limit = 10): static
    {
        $instance = clone $this;
        $placeholder = $instance->getPlaceholder();
        $vectorString = '[' . implode(',', $queryVector) . ']';
        
        $operator = match ($metric) {
            'cosine' => '<=>',
            'l2', 'euclidean' => '<->',
            'inner_product', 'dot' => '<#>',
            default => '<=>'
        };
        
        $instance->select[] = "({$column} {$operator} {$placeholder}) as distance";
        $instance->bindings['where'][] = $vectorString;
        
        $orderDirection = $metric === 'inner_product' ? 'DESC' : 'ASC';
        return $instance->orderBy("{$column} {$operator} '{$vectorString}'", $orderDirection)->limit($limit);
    }

    /**
     * Hybrid search combining vector similarity and full-text search.
     *
     * @param  string  $textColumn  The text column for full-text search.
     * @param  string  $vectorColumn  The vector column for similarity search.
     * @param  string  $query  The text query.
     * @param  array<float>  $queryVector  The query vector.
     * @param  float  $textWeight  Weight for text search (0-1).
     * @param  float  $vectorWeight  Weight for vector search (0-1).
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function hybridSearch(
        string $textColumn,
        string $vectorColumn,
        string $query,
        array $queryVector,
        float $textWeight = 0.3,
        float $vectorWeight = 0.7,
        int $limit = 10
    ): static {
        $instance = clone $this;
        $textPlaceholder = $instance->getPlaceholder();
        $vectorPlaceholder = $instance->getPlaceholder();
        $vectorString = '[' . implode(',', $queryVector) . ']';
        
        // Create hybrid score combining text rank and vector similarity
        $hybridScore = sprintf(
            "ts_rank(to_tsvector('%s', %s), plainto_tsquery(%s)) * %f + (1 - (%s <=> %s)) * %f",
            $this->ragConfig['text_search_config'],
            $textColumn,
            $textPlaceholder,
            $textWeight,
            $vectorColumn,
            $vectorPlaceholder,
            $vectorWeight
        );
        
        $instance->select[] = "{$hybridScore} as hybrid_score";
        $instance->bindings['where'][] = $query;
        $instance->bindings['where'][] = $vectorString;
        
        // Add conditions for both text and vector search
        $textSearchPlaceholder = $instance->getPlaceholder();
        $vectorSearchPlaceholder = $instance->getPlaceholder();
        
        $instance->where[] = sprintf(
            "(to_tsvector('%s', %s) @@ plainto_tsquery(%s) OR (1 - (%s <=> %s)) > 0.5)",
            $this->ragConfig['text_search_config'],
            $textColumn,
            $textSearchPlaceholder,
            $vectorColumn,
            $vectorSearchPlaceholder
        );
        
        $instance->bindings['where'][] = $query;
        $instance->bindings['where'][] = $vectorString;
        
        return $instance->orderBy('hybrid_score', 'DESC')->limit($limit);
    }

    /**
     * Semantic search with metadata filtering for RAG contexts.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  array<string, mixed>  $metadataFilters  Metadata filters.
     * @param  string  $vectorColumn  The vector column name.
     * @param  string  $metadataColumn  The metadata column name.
     * @param  float  $threshold  Similarity threshold.
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function semanticSearch(
        array $queryVector,
        array $metadataFilters = [],
        ?string $vectorColumn = null,
        ?string $metadataColumn = null,
        ?float $threshold = null,
        ?int $limit = null
    ): static {
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        $metadataColumn = $metadataColumn ?? $this->ragConfig['default_metadata_column'];
        $threshold = $threshold ?? $this->ragConfig['default_similarity_threshold'];
        $limit = $limit ?? $this->ragConfig['default_search_limit'];
        
        $instance = $this->vectorSimilarity($vectorColumn, $queryVector, $limit, $threshold);
        
        // Add metadata filters
        foreach ($metadataFilters as $key => $value) {
            if (is_array($value)) {
                $instance = $instance->whereJsonContains($metadataColumn, [$key => $value]);
            } else {
                $instance = $instance->whereJsonEquals($metadataColumn, $key, $value);
            }
        }
        
        return $instance;
    }

    /**
     * Multi-modal search supporting different content types.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  array<string>  $contentTypes  Content types to search.
     * @param  string  $vectorColumn  The vector column name.
     * @param  string  $metadataColumn  The metadata column name.
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function multiModalSearch(
        array $queryVector,
        array $contentTypes = ['text', 'image', 'audio'],
        ?string $vectorColumn = null,
        ?string $metadataColumn = null,
        ?int $limit = null
    ): static {
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        $metadataColumn = $metadataColumn ?? $this->ragConfig['default_metadata_column'];
        $limit = $limit ?? $this->ragConfig['default_search_limit'];
        
        $instance = $this->vectorSimilarity($vectorColumn, $queryVector, $limit);
        
        // Filter by content types
        $placeholder = $instance->getPlaceholder();
        $instance->where[] = "{$metadataColumn}->>'content_type' = ANY({$placeholder})";
        $instance->bindings['where'][] = '{' . implode(',', array_map(fn($t) => '"'.$t.'"', $contentTypes)) . '}';
        
        return $instance;
    }

    /**
     * Contextual search with conversation history support.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  string|null  $conversationId  The conversation ID.
     * @param  int  $contextWindow  Number of previous messages to consider.
     * @param  string  $vectorColumn  The vector column name.
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function contextualSearch(
        array $queryVector,
        ?string $conversationId = null,
        int $contextWindow = 5,
        ?string $vectorColumn = null,
        ?int $limit = null
    ): static {
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        $limit = $limit ?? $this->ragConfig['default_search_limit'];
        
        $instance = $this->vectorSimilarity($vectorColumn, $queryVector, $limit);
        
        if ($conversationId) {
            $instance = $instance->whereJsonEquals('metadata', 'conversation_id', $conversationId);
            
            $timestampBoost = "CASE WHEN metadata->>'conversation_id' = '{$conversationId}' 
                               THEN similarity_score + 0.1 
                               ELSE similarity_score END";
            $instance->select[] = "{$timestampBoost} as contextual_score";
            $instance = $instance->orderBy('contextual_score', 'DESC');
        }
        
        return $instance;
    }

    /**
     * Temporal search with time-based relevance decay.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  string  $timeColumn  The timestamp column name.
     * @param  float  $decayRate  Time decay rate (0-1).
     * @param  string  $vectorColumn  The vector column name.
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function temporalSearch(
        array $queryVector,
        string $timeColumn = 'created_at',
        float $decayRate = 0.1,
        ?string $vectorColumn = null,
        ?int $limit = null
    ): static {
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        $limit = $limit ?? $this->ragConfig['default_search_limit'];
        
        $instance = $this->vectorSimilarity($vectorColumn, $queryVector, $limit);
        
        // Add temporal decay to similarity score
        $temporalScore = "similarity_score * EXP(-{$decayRate} * EXTRACT(EPOCH FROM (NOW() - {$timeColumn})) / 86400)";
        $instance->select[] = "{$temporalScore} as temporal_score";
        
        return $instance->orderBy('temporal_score', 'DESC');
    }

    /**
     * Retrieval with source attribution for RAG citations.
     *
     * @param  array<float>  $queryVector  The query vector.
     * @param  array<string>  $sourceFields  Fields to include for citations.
     * @param  string  $vectorColumn  The vector column name.
     * @param  int  $limit  Number of results to return.
     * @return static Returns a new query builder instance for method chaining.
     */
    public function retrievalWithCitation(
        array $queryVector,
        array $sourceFields = ['title', 'source', 'url', 'author', 'created_at'],
        ?string $vectorColumn = null,
        ?int $limit = null
    ): static {
        $vectorColumn = $vectorColumn ?? $this->ragConfig['default_vector_column'];
        $limit = $limit ?? $this->ragConfig['default_search_limit'];
        
        $instance = $this->vectorSimilarity($vectorColumn, $queryVector, $limit);
        
        foreach ($sourceFields as $field) {
            if (!in_array($field, $instance->select)) {
                $instance->select[] = $field;
            }
        }
        
        $metadataColumn = $this->ragConfig['default_metadata_column'];
        $instance->select[] = "{$metadataColumn}->>'page_number' as page_number";
        $instance->select[] = "{$metadataColumn}->>'chunk_index' as chunk_index";
        $contentColumn = $this->ragConfig['default_content_column'];
        $instance->select[] = "LENGTH({$contentColumn}) as content_length";
        
        return $instance;
    }

    /**
     * Build vector index creation query.
     *
     * @param  string  $column  The vector column name.
     * @param  string  $method  Index method ('hnsw' or 'ivfflat').
     * @param  string  $operator  Distance operator class.
     * @param  array<string, mixed>  $options  Index options.
     * @return string The index creation SQL.
     */
    protected function buildVectorIndexQuery(
        string $column,
        string $method = 'hnsw',
        string $operator = 'vector_cosine_ops',
        array $options = []
    ): string {
        $indexName = "idx_{$this->table}_{$column}_{$method}";
        $sql = "CREATE INDEX IF NOT EXISTS {$indexName} ON {$this->table} USING {$method} ({$column} {$operator})";
        
        if (!empty($options)) {
            $optionPairs = [];
            foreach ($options as $key => $value) {
                $optionPairs[] = "{$key} = {$value}";
            }
            $sql .= " WITH (" . implode(', ', $optionPairs) . ")";
        }
        
        return $sql;
    }

    /**
     * Build chunk overlap query for document processing.
     *
     * @param  string  $contentColumn  The content column name.
     * @param  int  $chunkSize  Chunk size in characters.
     * @param  int  $overlapSize  Overlap size in characters.
     * @return string The chunking SQL query.
     */
    protected function buildChunkQuery(string $contentColumn, int $chunkSize = 1000, int $overlapSize = 200): string
    {
        return "
            WITH chunks AS (
                SELECT 
                    id,
                    generate_series(1, length({$contentColumn}), {$chunkSize} - {$overlapSize}) as start_pos,
                    {$contentColumn}
                FROM {$this->table}
            )
            SELECT 
                id,
                substring({$contentColumn} FROM start_pos FOR {$chunkSize}) as chunk_text,
                start_pos,
                start_pos + {$chunkSize} - 1 as end_pos,
                (start_pos - 1) / ({$chunkSize} - {$overlapSize}) + 1 as chunk_index
            FROM chunks
            WHERE length(trim(substring({$contentColumn} FROM start_pos FOR {$chunkSize}))) > 0
        ";
    }
}