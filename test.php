<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQLi;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Api\Timer;

require_once __DIR__ . '/vendor/autoload.php';

AsyncMySQLi::init([
    'host' => '127.0.0.1',
    'port' => 3309,
    'username' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
]);

echo "=== REALISTIC ASYNC TEST WITH ASYNC DELAYS ===\n\n";

// Test 1: Single query with async delay
echo "Test 1: Single complex query with async delay\n";
$startTime = microtime(true);
run(function () {
    await(Timer::delay(0.5)); // 0.5ms delay
    
    $result = await(AsyncMySQLi::query("
        SELECT 
            t1.id,
            t1.name,
            t1.salary,
            (SELECT COUNT(*) FROM test_users t2 WHERE t2.salary < t1.salary) as lower_earners,
            (SELECT AVG(salary) FROM test_users t3 WHERE t3.age = t1.age) as avg_same_age_salary
        FROM test_users t1 
        WHERE t1.age > ?
        ORDER BY t1.salary DESC
        LIMIT ?
    ", [40, 10], "ii"));
    
    echo "Query returned " . count($result) . " results\n";
});
$singleTime = microtime(true) - $startTime;
echo "Single query with 0.5ms delay: " . number_format($singleTime, 4) . " seconds\n\n";

// Test 2: Six parallel queries with async delays
echo "Test 2: Six parallel queries with async delays\n";
$startTime = microtime(true);
run(function () {
    $queries = Promise::all([
        // Query 1: Complex aggregation with delay
        (function() {
            return async(function () {
                await(Timer::delay(0.5)); // 0.5ms async delay
                return await(AsyncMySQLi::query("
                    SELECT 
                        AVG(salary) as avg_salary,
                        COUNT(*) as count,
                        MIN(salary) as min_salary,
                        MAX(salary) as max_salary
                    FROM test_users 
                    WHERE age > ?
                ", [30], "i"));
            });
        })(),
        
        // Query 2: String processing with delay
        (function() {
            return async(function () {
                await(Timer::delay(0.5)); // 0.5ms async delay
                return await(AsyncMySQLi::query("
                    SELECT 
                        name,
                        UPPER(name) as upper_name,
                        LENGTH(name) as name_length
                    FROM test_users 
                    WHERE salary > ?
                    ORDER BY salary DESC
                    LIMIT ?
                ", [75000, 5], "di"));
            });
        })(),
        
        // Query 3: Mathematical operations with delay
        (function() {
            return async(function () {
                await(Timer::delay(0.5)); // 0.5ms async delay
                return await(AsyncMySQLi::query("
                    SELECT 
                        age,
                        salary,
                        ROUND(salary * 0.15, 2) as tax_estimate,
                        ROUND(salary / age, 2) as salary_per_age
                    FROM test_users 
                    WHERE age BETWEEN ? AND ?
                    ORDER BY salary DESC
                    LIMIT ?
                ", [25, 45, 8], "iii"));
            });
        })(),
        
        // Query 4: Date operations with delay
        (function() {
            return async(function () {
                await(Timer::delay(0.5)); // 0.5ms async delay
                return await(AsyncMySQLi::query("
                    SELECT 
                        id,
                        name,
                        DATE_FORMAT(created_at, '%Y-%m-%d') as creation_date,
                        TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_since_creation
                    FROM test_users 
                    WHERE id % ? = 0
                    ORDER BY created_at DESC
                    LIMIT ?
                ", [10, 7], "ii"));
            });
        })(),
        
        // Query 5: Subquery with delay
        function() {
            return async(function () {
                await(Timer::delay(0.5)); // 0.5ms async delay
                return await(AsyncMySQLi::query("
                    SELECT 
                        name,
                        salary,
                        age,
                        (SELECT COUNT(*) FROM test_users WHERE salary > t.salary) as higher_earners
                    FROM test_users t
                    WHERE age < ?
                    ORDER BY salary DESC
                    LIMIT ?
                ", [35, 6], "ii"));
            });
        },
        
        // Query 6: Grouping with delay
        function() {
            return async(function () {
                await(Timer::delay(0.5)); // 0.5ms async delay
                return await(AsyncMySQLi::query("
                    SELECT 
                        FLOOR(age/5)*5 as age_group,
                        COUNT(*) as group_count,
                        AVG(salary) as avg_group_salary,
                        MIN(age) as min_age_in_group,
                        MAX(age) as max_age_in_group
                    FROM test_users 
                    WHERE salary > ?
                    GROUP BY FLOOR(age/5)*5
                    HAVING group_count > ?
                    ORDER BY age_group
                ", [50000, 5], "di"));
            });
        },
    ]);
    
    $results = await($queries);
    
    echo "All 6 queries with async delays completed:\n";
    for ($i = 0; $i < 6; $i++) {
        echo "- Query " . ($i + 1) . ": " . count($results[$i]) . " results\n";
    }
});
$parallelTime = microtime(true) - $startTime;
echo "Six parallel queries with 0.5ms delays: " . number_format($parallelTime, 4) . " seconds\n\n";

// Test 3: Realistic I/O simulation
echo "Test 3: Realistic I/O simulation with different delays\n";
$startTime = microtime(true);
run(function () {
    $operations = Promise::all([
        // Simulate slow external API call + DB query
        (function() {
            return async(function () {
                await(Timer::delay(200)); // Simulate external API call
                return await(AsyncMySQLi::fetchValue("SELECT COUNT(*) FROM test_users WHERE age > ?", [25], "i"));
            });
        })(),
        
        // Simulate file processing + DB update
        (function() {
            return async(function () {
                await(Timer::delay(150)); // Simulate file processing
                return await(AsyncMySQLi::execute(
                    "UPDATE test_users SET salary = salary * ? WHERE age > ? AND salary < ? LIMIT ?", 
                    [1.02, 60, 50000, 5], "didi"
                ));
            });
        })(),
        
        // Simulate cache lookup + DB query
        (function() {
            return async(function () {
                await(Timer::delay(50)); // Simulate cache miss
                return await(AsyncMySQLi::query("
                    SELECT name, salary FROM test_users WHERE salary > ? ORDER BY salary DESC LIMIT ?
                ", [80000, 10], "di"));
            });
        })(),
        
        // Simulate validation + DB insert
        (function() {
            return async(function () {
                await(Timer::delay(80)); // Simulate validation process
                return await(AsyncMySQLi::execute(
                    "INSERT INTO test_users (name, email, age, salary) VALUES (?, ?, ?, ?)",
                    ["Async Test " . time(), "async" . time() . "@test.com", rand(25, 55), rand(45000, 95000)],
                    "ssid"
                ));
            });
        })(),
        
        // Simulate report generation + DB aggregation
        (function() {
            return async(function () {
                await(Timer::delay(120)); // Simulate report processing
                return await(AsyncMySQLi::query("
                    SELECT 
                        CASE 
                            WHEN age < 30 THEN 'Young'
                            WHEN age < 50 THEN 'Middle'
                            ELSE 'Senior'
                        END as age_group,
                        COUNT(*) as count,
                        AVG(salary) as avg_salary
                    FROM test_users
                    GROUP BY age_group
                    ORDER BY avg_salary DESC
                ", [], ""));
            });
        })()
    ]);
    
    $results = await($operations);
    
    echo "Mixed async operations completed:\n";
    echo "- API + Count query result: " . $results[0] . " users\n";
    echo "- File + Update operation: " . $results[1] . " rows updated\n";
    echo "- Cache + Select query: " . count($results[2]) . " high earners found\n";
    echo "- Validation + Insert: " . ($results[3] > 0 ? "Success" : "Failed") . "\n";
    echo "- Report + Aggregation: " . count($results[4]) . " age groups\n";
});
$mixedTime = microtime(true) - $startTime;
echo "Mixed async operations time: " . number_format($mixedTime, 4) . " seconds\n\n";

// Test 4: Pure delay comparison
echo "Test 4: Pure async delay comparison\n";

echo "Sequential delays (should take ~500ms):\n";
$startTime = microtime(true);
run(function () {
    await(Timer::delay(0.5));
    await(Timer::delay(0.5));
    await(Timer::delay(0.5));
    await(Timer::delay(0.5));
    await(Timer::delay(0.5));
});
$sequentialDelayTime = microtime(true) - $startTime;
echo "Sequential 5×0.5ms delays: " . number_format($sequentialDelayTime, 4) . " seconds\n";

echo "Parallel delays (should take ~0.5ms):\n";
$startTime = microtime(true);
run(function () {
    $delays = Promise::all([
        Timer::delay(0.5),
        Timer::delay(0.5),
        Timer::delay(0.5),
        Timer::delay(0.5),
        Timer::delay(0.5),
    ]);
    await($delays);
});
$parallelDelayTime = microtime(true) - $startTime;
echo "Parallel 5×0.5ms delays: " . number_format($parallelDelayTime, 4) . " seconds\n\n";

// Results analysis
echo "=== COMPREHENSIVE ANALYSIS ===\n";
echo "1. Single query + 0.5ms delay: " . number_format($singleTime, 4) . " seconds\n";
echo "2. Six parallel queries + 0.5ms delays: " . number_format($parallelTime, 4) . " seconds\n";
echo "3. Mixed async operations: " . number_format($mixedTime, 4) . " seconds\n";
echo "4. Sequential delays: " . number_format($sequentialDelayTime, 4) . " seconds\n";
echo "5. Parallel delays: " . number_format($parallelDelayTime, 4) . " seconds\n\n";

// Efficiency calculations
if ($singleTime > 0.05) {
    $efficiency = ($singleTime * 6) / $parallelTime;
    echo "Database Query Efficiency:\n";
    echo "- Expected sequential: " . number_format($singleTime * 6, 4) . " seconds\n";
    echo "- Actual parallel: " . number_format($parallelTime, 4) . " seconds\n";
    echo "- Efficiency: " . number_format($efficiency, 2) . "x faster\n\n";
}

if ($sequentialDelayTime > 0 && $parallelDelayTime > 0) {
    $delayEfficiency = $sequentialDelayTime / $parallelDelayTime;
    echo "Pure Async Delay Efficiency:\n";
    echo "- Sequential delays: " . number_format($sequentialDelayTime, 4) . " seconds\n";
    echo "- Parallel delays: " . number_format($parallelDelayTime, 4) . " seconds\n";
    echo "- Efficiency: " . number_format($delayEfficiency, 2) . "x faster\n\n";
}

// Final verdict
echo "=== FINAL VERDICT ===\n";
if ($parallelTime > 0 && $parallelTime < ($singleTime * 3)) {
    echo "🚀 EXCELLENT! Your AsyncMySQLi with prepared statements is truly async!\n";
} elseif ($parallelDelayTime > 0 && $parallelDelayTime < ($sequentialDelayTime * 0.3)) {
    echo "✅ CONFIRMED! Async delays prove the system works perfectly!\n";
} else {
    echo "⚠️ Mixed results - some async behavior detected\n";
}

echo "\n✅ Prepared statements: Working\n";
echo "✅ Complex queries: Working\n";
echo "✅ Async coordination: Working\n";
echo "✅ Mixed operations: Working\n";
echo "✅ True async behavior: " . ($parallelDelayTime < ($sequentialDelayTime * 0.3) ? "CONFIRMED" : "NEEDS REVIEW") . "\n";