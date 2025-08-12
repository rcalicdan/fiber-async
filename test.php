<?php

// ---------------------------------------------------------------------------------------------------------------------
// 1. Setup - Include Autoloader and Global Helpers
// ---------------------------------------------------------------------------------------------------------------------
require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\AsyncPostgreSQL;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer; // Import Timer for delay function
use Rcalicdan\FiberAsync\Api\Async; // Import Async for proper async context
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;

// ---------------------------------------------------------------------------------------------------------------------
// 2. PostgreSQL Configuration
// ---------------------------------------------------------------------------------------------------------------------
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 5432,
    'database' => 'aladyn_api',
    'username' => 'postgres',
    'password' => 'root',
];

// ---------------------------------------------------------------------------------------------------------------------
// 3. Database Initialization and Setup
// ---------------------------------------------------------------------------------------------------------------------
echo "--- Initializing PostgreSQL and setting up test table ---\n";
try {
    AsyncPostgreSQL::init($dbConfig, 15);

    Task::run(function() {
        // Drop table if it exists
        await(AsyncPostgreSQL::execute("DROP TABLE IF EXISTS compute_heavy_data"));
        echo "Table 'compute_heavy_data' dropped if it existed.\n";

        // Create table with better data types
        await(AsyncPostgreSQL::execute("
            CREATE TABLE compute_heavy_data (
                id SERIAL PRIMARY KEY,
                val_int INTEGER,
                val_text TEXT,
                val_numeric NUMERIC(10,2),
                created_at TIMESTAMP DEFAULT NOW()
            )
        "));
        echo "Table 'compute_heavy_data' created.\n";

        // Insert more rows with better data distribution
        echo "Inserting 100,000 rows for CPU-bound queries...\n";
        await(AsyncPostgreSQL::execute("
            INSERT INTO compute_heavy_data (val_int, val_text, val_numeric)
            SELECT 
                s.id,
                MD5(RANDOM()::TEXT || s.id::TEXT || clock_timestamp()::TEXT),
                RANDOM() * 1000 + s.id * 0.01
            FROM generate_series(1, 100000) AS s(id)
        "));
        echo "100,000 rows inserted for computation.\n";

        // Add indices for better performance testing
        await(AsyncPostgreSQL::execute("CREATE INDEX idx_val_int_mod ON compute_heavy_data (val_int) WHERE val_int % 7 = 0"));
        await(AsyncPostgreSQL::execute("CREATE INDEX idx_val_int_mod3 ON compute_heavy_data (val_int) WHERE val_int % 3 = 0"));
        echo "Indices created.\n";
    });
    echo "Database setup complete.\n\n";

} catch (Exception $e) {
    echo "Database setup FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Query Definitions (Fixed with proper async context)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * CPU-intensive query
 */
function run_heavy_cpu_query(string $param): PromiseInterface {
    return Async::async(function() use ($param) {
        $sql = "
            WITH RECURSIVE heavy_computation AS (
                SELECT 
                    id,
                    val_int,
                    val_text,
                    val_numeric,
                    -- Multiple expensive operations
                    MD5(val_text || $1 || id::TEXT) as hash1,
                    MD5(MD5(val_text || $1) || MD5(id::TEXT)) as hash2,
                    MD5(MD5(MD5(val_text || $1)) || MD5(MD5(id::TEXT))) as hash3,
                    -- Fixed: Use NUMERIC to avoid overflow
                    val_int::NUMERIC * val_int::NUMERIC as val_squared,
                    POWER(val_int::NUMERIC, 2) + SQRT(val_int::NUMERIC) as math_result,
                    -- String operations (CPU intensive)
                    REPEAT(SUBSTRING(val_text, 1, 10), 5) as repeated_text,
                    -- Pattern matching
                    CASE 
                        WHEN val_text ~ '[0-9a-f]{8}[0-9a-f]{8}' THEN 'long_hex'
                        WHEN val_text ~ '[a-z]{10,}' THEN 'long_alpha'
                        WHEN val_text ~ '[0-9]{5,}' THEN 'many_digits'
                        ELSE 'other'
                    END as pattern_match,
                    -- Expensive text operations
                    LENGTH(TRANSLATE(val_text, '0123456789abcdef', 'FEDCBA9876543210')) as translate_len
                FROM compute_heavy_data 
                WHERE val_int % 7 = 0
                LIMIT 20000
            ),
            aggregated_data AS (
                SELECT 
                    COUNT(*) as total_rows,
                    SUM(LENGTH(hash1) + LENGTH(hash2) + LENGTH(hash3)) as total_hash_length,
                    ROUND(AVG(val_squared), 2) as avg_squared,
                    ROUND(AVG(math_result), 2) as avg_math,
                    ROUND(AVG(val_numeric), 2) as avg_numeric,
                    STRING_AGG(DISTINCT pattern_match, '|' ORDER BY pattern_match) as pattern_summary,
                    SUM(translate_len) as total_translate_len
                FROM heavy_computation
            ),
            final_computation AS (
                SELECT 
                    *,
                    MD5(pattern_summary || $1 || total_rows::TEXT) as final_hash1,
                    MD5(MD5(pattern_summary || $1) || total_hash_length::TEXT) as final_hash2
                FROM aggregated_data
            )
            SELECT 
                total_rows,
                total_hash_length,
                avg_squared,
                avg_math,
                avg_numeric,
                LENGTH(pattern_summary) as pattern_summary_len,
                total_translate_len,
                final_hash1,
                final_hash2,
                MD5(final_hash1 || final_hash2 || $1) as ultimate_hash
            FROM final_computation;
        ";
        
        return await(AsyncPostgreSQL::fetchOne($sql, [$param]));
    })();
}

/**
 * Alternative heavy query
 */
function run_alternative_heavy_query(string $param): PromiseInterface {
    return Async::async(function() use ($param) {
        $sql = "
            SELECT 
                $1 as param,
                COUNT(*) as row_count,
                SUM(LENGTH(MD5(val_text || $1 || id::TEXT))) as hash_sum,
                ROUND(AVG(val_int::NUMERIC * val_int::NUMERIC), 2) as avg_square,
                ROUND(STDDEV(val_numeric), 4) as stddev_numeric,
                LEFT(STRING_AGG(SUBSTRING(MD5(val_text || $1), 1, 8), ''), 1000) as concat_hashes,
                SUM(CASE 
                    WHEN val_int % 2 = 0 THEN POWER(val_int::NUMERIC, 1.5)::INTEGER
                    ELSE CEIL(SQRT(val_int::NUMERIC))::INTEGER
                END) as conditional_math,
                COUNT(CASE WHEN val_text ~ '[0-9a-f]{16}' THEN 1 END) as hex_count,
                COUNT(CASE WHEN LENGTH(val_text) > 25 THEN 1 END) as long_text_count
            FROM compute_heavy_data 
            WHERE val_int % 3 = 0
              AND val_int BETWEEN 100 AND 50000
            GROUP BY $1;
        ";
        
        return await(AsyncPostgreSQL::fetchOne($sql, [$param]));
    })();
}

/**
 * FIXED: I/O simulation with proper async delay - this should show clear concurrency benefits
 */
function run_io_simulation_query(string $param): PromiseInterface {
    return Async::async(function() use ($param) {
        // Simulate I/O delay using proper async delay function
        echo "Starting I/O simulation for {$param}...\n";
        await(Timer::delay(0.5)); // 500ms async delay - this won't block other operations!
        echo "I/O delay completed for {$param}, running query...\n";
        
        $sql = "
            SELECT 
                $1 as param,
                COUNT(*) as count,
                ROUND(AVG(val_numeric), 2) as avg_val,
                MIN(val_int) as min_val,
                MAX(val_int) as max_val
            FROM compute_heavy_data 
            WHERE val_int % 11 = 0
            LIMIT 1000;
        ";
        
        $result = await(AsyncPostgreSQL::fetchOne($sql, [$param]));
        echo "Query completed for {$param}\n";
        return $result;
    })();
}

/**
 * Mixed workload - combines CPU work with I/O delays
 */
function run_mixed_workload_query(string $param): PromiseInterface {
    return Async::async(function() use ($param) {
        // Some initial computation
        $sql1 = "SELECT COUNT(*) as count FROM compute_heavy_data WHERE val_int % 13 = 0";
        $count = await(AsyncPostgreSQL::fetchValue($sql1));
        
        // Simulate network I/O or file I/O
        echo "Starting mixed workload for {$param} (found {$count} records)...\n";
        await(Timer::delay(0.3)); // 300ms delay
        
        // More computation after the delay
        $sql2 = "
            SELECT 
                $1 as param,
                {$count} as initial_count,
                COUNT(*) as final_count,
                SUM(LENGTH(MD5(val_text || $1))) as hash_work
            FROM compute_heavy_data 
            WHERE val_int % 17 = 0 
            LIMIT 5000;
        ";
        
        $result = await(AsyncPostgreSQL::fetchOne($sql2, [$param]));
        echo "Mixed workload completed for {$param}\n";
        return $result;
    })();
}

// ---------------------------------------------------------------------------------------------------------------------
// 5. Test Execution Functions
// ---------------------------------------------------------------------------------------------------------------------

function runSequentialTest(callable $queryFunc, array $params, string $testName): array {
    echo "--- Running Sequential {$testName} ---\n";
    $startTime = microtime(true);
    
    Task::run(function() use ($queryFunc, $params) {
        foreach ($params as $i => $param) {
            $qStartTime = microtime(true);
            $result = await($queryFunc($param));
            $qEndTime = microtime(true);
            echo "Query " . ($i + 1) . " time: " . round($qEndTime - $qStartTime, 3) . "s";
            if (isset($result['total_rows'])) {
                echo " (Rows: {$result['total_rows']})";
            } elseif (isset($result['row_count'])) {
                echo " (Rows: {$result['row_count']})";
            } elseif (isset($result['count'])) {
                echo " (Count: {$result['count']})";
            } elseif (isset($result['final_count'])) {
                echo " (Count: {$result['final_count']})";
            }
            echo "\n";
        }
    });
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    echo "Total Sequential Duration: " . round($duration, 3) . " seconds\n\n";
    
    return ['duration' => $duration, 'type' => 'sequential'];
}

function runConcurrentTest(callable $queryFunc, array $params, string $testName): array {
    echo "--- Running Concurrent {$testName} ---\n";
    $startTime = microtime(true);
    
    Task::run(function() use ($queryFunc, $params) {
        $promises = [];
        foreach ($params as $i => $param) {
            $promises[] = $queryFunc($param)->then(function ($r) use ($i, $param) {
                echo "Query " . ($i + 1) . " ({$param}) finished";
                if (isset($r['total_rows'])) {
                    echo " (Rows: {$r['total_rows']})";
                } elseif (isset($r['row_count'])) {
                    echo " (Rows: {$r['row_count']})";
                } elseif (isset($r['count'])) {
                    echo " (Count: {$r['count']})";
                } elseif (isset($r['final_count'])) {
                    echo " (Count: {$r['final_count']})";
                }
                echo "\n";
                return $r;
            });
        }
        
        $results = await(all($promises));
        echo "All concurrent queries completed. Results: " . count($results) . "\n";
    });
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    echo "Total Concurrent Duration: " . round($duration, 3) . " seconds\n\n";
    
    return ['duration' => $duration, 'type' => 'concurrent'];
}

// ---------------------------------------------------------------------------------------------------------------------
// 6. Run Tests
// ---------------------------------------------------------------------------------------------------------------------

$testParams = ['test_a', 'test_b', 'test_c'];

// Test 1: Heavy CPU-bound queries (may not show big improvement due to DB being bottleneck)
$seq1 = runSequentialTest('run_heavy_cpu_query', $testParams, 'Heavy CPU Queries');
$con1 = runConcurrentTest('run_heavy_cpu_query', $testParams, 'Heavy CPU Queries');

// Test 2: Alternative heavy queries
$seq2 = runSequentialTest('run_alternative_heavy_query', $testParams, 'Alternative Heavy Queries');
$con2 = runConcurrentTest('run_alternative_heavy_query', $testParams, 'Alternative Heavy Queries');

// Test 3: I/O simulation (should show CLEAR concurrency benefits now!)
$seq3 = runSequentialTest('run_io_simulation_query', ['io_a', 'io_b', 'io_c'], 'I/O Simulation Queries');
$con3 = runConcurrentTest('run_io_simulation_query', ['io_a', 'io_b', 'io_c'], 'I/O Simulation Queries');

// Test 4: Mixed workload (should also show good concurrency benefits)
$seq4 = runSequentialTest('run_mixed_workload_query', ['mix_a', 'mix_b', 'mix_c'], 'Mixed Workload Queries');
$con4 = runConcurrentTest('run_mixed_workload_query', ['mix_a', 'mix_b', 'mix_c'], 'Mixed Workload Queries');

// ---------------------------------------------------------------------------------------------------------------------
// 7. Results Analysis
// ---------------------------------------------------------------------------------------------------------------------

echo "=== PERFORMANCE TEST RESULTS ===\n\n";

function analyzeResults($sequential, $concurrent, $testName): bool {
    $improvement = $sequential['duration'] > 0 ? $sequential['duration'] / $concurrent['duration'] : 0;
    
    echo "{$testName}:\n";
    echo "  Sequential: " . round($sequential['duration'], 3) . "s\n";
    echo "  Concurrent: " . round($concurrent['duration'], 3) . "s\n";
    echo "  Improvement: " . round($improvement, 2) . "x ";
    
    if ($improvement > 1.5) {
        echo "✅ EXCELLENT\n";
        return true;
    } elseif ($improvement > 1.2) {
        echo "✅ GOOD\n";
        return true;
    } elseif ($improvement > 0.9) {
        echo "⚠️ MARGINAL\n";
        return false;
    } else {
        echo "❌ SLOWER\n";
        return false;
    }
}

$test1Pass = analyzeResults($seq1, $con1, "Heavy CPU Test");
$test2Pass = analyzeResults($seq2, $con2, "Alternative Heavy Test");
$test3Pass = analyzeResults($seq3, $con3, "I/O Simulation Test");
$test4Pass = analyzeResults($seq4, $con4, "Mixed Workload Test");

echo "\n=== ANALYSIS ===\n";

if ($test3Pass || $test4Pass) {
    echo "✅ SUCCESS: Async operations show clear benefits for I/O-bound work!\n";
    if ($test3Pass) echo "   - I/O simulation test passed (as expected)\n";
    if ($test4Pass) echo "   - Mixed workload test passed (as expected)\n";
} else {
    echo "❌ UNEXPECTED: I/O-bound tests should have shown major improvements\n";
}

if ($test1Pass || $test2Pass) {
    echo "✅ BONUS: CPU-bound tests also showed some concurrency benefits\n";
} else {
    echo "ℹ️ EXPECTED: CPU-bound tests showed limited benefits (DB is the bottleneck)\n";
}

// Cleanup
echo "\n--- Cleaning up ---\n";
try {
    Task::run(function() {
        await(AsyncPostgreSQL::execute("DROP TABLE IF EXISTS compute_heavy_data"));
        echo "Test table dropped.\n";
    });
} catch (Exception $e) {
    echo "Cleanup FAILED: " . $e->getMessage() . "\n";
}

AsyncPostgreSQL::reset();
echo "AsyncPostgreSQL reset complete.\n";

$overallPass = $test3Pass || $test4Pass; // I/O tests should definitely pass
echo "\n" . ($overallPass ? "🎉 OVERALL TEST RESULT: PASSED" : "❌ OVERALL TEST RESULT: FAILED") . "\n";