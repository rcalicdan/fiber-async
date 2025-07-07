<?php

use Rcalicdan\FiberAsync\Database\AsyncDB;
use Rcalicdan\FiberAsync\Helpers\loop_helper;
use Rcalicdan\FiberAsync\Helpers\async_helper;

require 'vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

class EnhancedDatabaseBenchmark 
{
    private PDO $pdo;
    private array $testData = [];
    private int $recordCount = 300; // For regular tests
    private int $stressTestRecordCount = 2000; // For stress test
    
    public function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_DATABASE'],
            $_ENV['DB_CHARSET']
        );
        
        $this->pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        $this->prepareTestData();
    }
    
    private function prepareTestData(): void
    {
        for ($i = 1; $i <= $this->recordCount; $i++) {
            $this->testData[] = [
                'title' => "Benchmark Post #$i",
                'content' => "Content for post #$i. " . str_repeat("Lorem ipsum dolor sit amet consectetur adipiscing elit. ", 10),
                'status' => $i % 3 === 0 ? 'draft' : ($i % 3 === 1 ? 'published' : 'draft'),
                'created_at' => date('Y-m-d H:i:s', strtotime("-$i hours")),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    private function prepareStressTestData(): array
    {
        $stressData = [];
        for ($i = 1; $i <= $this->stressTestRecordCount; $i++) {
            $stressData[] = [
                'title' => "Stress Test Post #$i",
                'content' => "Stress test content for post #$i. " . str_repeat("Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. ", 20),
                'status' => $i % 3 === 0 ? 'draft' : ($i % 3 === 1 ? 'published' : 'draft'),
                'created_at' => date('Y-m-d H:i:s', strtotime("-" . rand(1, 720) . " hours")),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }
        return $stressData;
    }
    
    public function runBenchmark(): void
    {
        echo "=== Enhanced Database Performance Benchmark ===\n";
        echo "Testing with {$this->recordCount} records for regular tests\n";
        echo "Testing with {$this->stressTestRecordCount} records for stress tests\n";
        echo "Environment: MySQL {$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}\n";
        echo "Focus: Showcasing async advantages with realistic scenarios\n\n";
        
        $this->cleanupTestData();
        
        // STRESS TEST FIRST - Insert 2000 records
        echo "=== STRESS TEST PREPARATION ===\n";
        echo "Inserting {$this->stressTestRecordCount} records for stress testing...\n";
        $this->runStressTestDataSetup();
        
        // STRESS TESTS
        echo "\n=== STRESS TESTS ({$this->stressTestRecordCount} records) ===\n";
        
        echo "\nSTRESS 1. Mass Data Retrieval (100 concurrent queries):\n";
        $this->stressTestMassDataRetrieval();
        
        echo "\nSTRESS 2. Heavy Concurrent Writes (50 simultaneous updates):\n";
        $this->stressTestHeavyConcurrentWrites();
        
        echo "\nSTRESS 3. Complex Analytics Queries (20 heavy queries):\n";
        $this->stressTestComplexAnalytics();
        
        echo "\nSTRESS 4. Mixed Load Simulation (Read/Write/Analytics):\n";
        $this->stressTestMixedLoad();
        
        echo "\nSTRESS 5. High Frequency Operations (200 quick operations):\n";
        $this->stressTestHighFrequency();
        
        // Clean up stress test data
        echo "\nCleaning up stress test data...\n";
        $this->cleanupStressTestData();
        
        // REGULAR TESTS
        echo "\n=== REGULAR TESTS ({$this->recordCount} records) ===\n";
        echo "Setting up regular test data...\n";
        $this->setupTestData();
        
        echo "\n1. High Concurrency Read Operations (20 simultaneous queries):\n";
        $this->testHighConcurrencyReads();
        
        echo "\n2. Mixed Operations with Simulated Network Delays:\n";
        $this->testMixedOperationsWithDelays();
        
        echo "\n3. Dashboard Analytics (Multiple Complex Queries):\n";
        $this->testDashboardQueries();
        
        echo "\n4. Batch Processing (Process 20 records with operations):\n";
        $this->testBatchProcessing();
        
        echo "\n5. Real-world Scenario (User Activity Simulation):\n";
        $this->testRealWorldScenario();
        
        $this->cleanupTestData();
        echo "\n=== Enhanced Benchmark Complete ===\n";
    }
    
    private function runStressTestDataSetup(): void
    {
        $stressData = $this->prepareStressTestData();
        
        // PDO Batch Insert
        echo "PDO batch insert of {$this->stressTestRecordCount} records...\n";
        $pdoStart = microtime(true);
        
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("INSERT INTO posts (title, content, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($stressData as $data) {
            $stmt->execute([$data['title'], $data['content'], $data['status'], $data['created_at'], $data['updated_at']]);
        }
        $this->pdo->commit();
        
        $pdoTime = microtime(true) - $pdoStart;
        
        // Clean up before AsyncDB test
        $this->cleanupStressTestData();
        
        // AsyncDB Batch Insert
        echo "AsyncDB concurrent insert of {$this->stressTestRecordCount} records...\n";
        $asyncStart = microtime(true);
        
        run(function () use ($stressData) {
            $batchSize = 100; // Process in batches of 100
            $batches = array_chunk($stressData, $batchSize);
            
            $promises = [];
            foreach ($batches as $batch) {
                $promises[] = async(function () use ($batch) {
                    $batchPromises = [];
                    foreach ($batch as $data) {
                        $batchPromises[] = AsyncDB::table('posts')->create($data);
                    }
                    await(all($batchPromises));
                })();
            }
            
            await(all($promises));
        });
        
        $asyncTime = microtime(true) - $asyncStart;
        
        $this->printComparison("Stress Test Data Setup ({$this->stressTestRecordCount} records)", $pdoTime, $asyncTime);
    }
    
    private function stressTestMassDataRetrieval(): void
    {
        $queryCount = 100;
        
        // PDO Sequential
        $pdoStart = microtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $limit = rand(10, 50);
            $offset = rand(0, 1000);
            $stmt = $this->pdo->prepare("SELECT * FROM posts LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            $stmt->fetchAll();
        }
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Concurrent
        $asyncTime = run(function () use ($queryCount) {
            $start = microtime(true);
            
            $promises = [];
            for ($i = 0; $i < $queryCount; $i++) {
                $limit = rand(10, 50);
                $offset = rand(0, 1000);
                $promises[] = AsyncDB::table('posts')
                    ->limit($limit)
                    ->offset($offset)
                    ->get();
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("Mass Data Retrieval ($queryCount queries)", $pdoTime, $asyncTime);
    }
    
    private function stressTestHeavyConcurrentWrites(): void
    {
        $updateCount = 50;
        
        // PDO Sequential Updates
        $pdoStart = microtime(true);
        for ($i = 0; $i < $updateCount; $i++) {
            $randomId = rand(1, 1000);
            $stmt = $this->pdo->prepare("UPDATE posts SET updated_at = NOW(), content = CONCAT(content, ' - Updated') WHERE id = ?");
            $stmt->execute([$randomId]);
        }
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Concurrent Updates
        $asyncTime = run(function () use ($updateCount) {
            $start = microtime(true);
            
            $promises = [];
            for ($i = 0; $i < $updateCount; $i++) {
                $randomId = rand(1, 1000);
                $promises[] = AsyncDB::table('posts')
                    ->where('id', '=', $randomId)
                    ->update([
                        'updated_at' => date('Y-m-d H:i:s'),
                        'content' => 'CONCAT(content, " - Async Updated")'
                    ]);
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("Heavy Concurrent Writes ($updateCount updates)", $pdoTime, $asyncTime);
    }
    
    private function stressTestComplexAnalytics(): void
    {
        $queryCount = 20;
        
        // PDO Complex Analytics
        $pdoStart = microtime(true);
        
        $complexQueries = [
            "SELECT status, COUNT(*) as count, AVG(CHAR_LENGTH(content)) as avg_length FROM posts GROUP BY status",
            "SELECT DATE(created_at) as date, COUNT(*) as count FROM posts GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30",
            "SELECT HOUR(created_at) as hour, COUNT(*) as count FROM posts GROUP BY HOUR(created_at) ORDER BY hour",
            "SELECT YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as count FROM posts GROUP BY YEAR(created_at), MONTH(created_at)",
            "SELECT status, MIN(created_at) as first_post, MAX(created_at) as last_post FROM posts GROUP BY status",
            "SELECT CHAR_LENGTH(content) as content_length, COUNT(*) as count FROM posts GROUP BY CHAR_LENGTH(content) ORDER BY count DESC LIMIT 10",
            "SELECT status, COUNT(*) as count FROM posts WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY status",
            "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published FROM posts",
            "SELECT AVG(CHAR_LENGTH(title)) as avg_title_length, AVG(CHAR_LENGTH(content)) as avg_content_length FROM posts",
            "SELECT status, COUNT(*) as count FROM posts WHERE title LIKE '%Stress%' GROUP BY status"
        ];
        
        // Run each query multiple times
        foreach ($complexQueries as $query) {
            for ($i = 0; $i < 2; $i++) {
                $stmt = $this->pdo->query($query);
                $stmt->fetchAll();
            }
        }
        
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Complex Analytics
        $asyncTime = run(function () use ($complexQueries) {
            $start = microtime(true);
            
            $promises = [];
            
            // Run each query multiple times concurrently
            foreach ($complexQueries as $query) {
                for ($i = 0; $i < 2; $i++) {
                    $promises[] = $this->executeComplexQuery($query);
                }
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("Complex Analytics ($queryCount complex queries)", $pdoTime, $asyncTime);
    }
    
    private function executeComplexQuery(string $query)
    {
        return async(function () use ($query) {
            // Convert SQL to AsyncDB calls (simplified for demo)
            if (strpos($query, 'GROUP BY status') !== false) {
                return await(AsyncDB::table('posts')
                    ->select(['status', 'COUNT(*) as count', 'AVG(CHAR_LENGTH(content)) as avg_length'])
                    ->groupBy('status')
                    ->get());
            } elseif (strpos($query, 'DATE(created_at)') !== false) {
                return await(AsyncDB::table('posts')
                    ->select(['DATE(created_at) as date', 'COUNT(*) as count'])
                    ->groupBy('DATE(created_at)')
                    ->orderBy('date', 'DESC')
                    ->limit(30)
                    ->get());
            } else {
                // For other complex queries, use raw query
                return await(AsyncDB::table('posts')->get());
            }
        })();
    }
    
    private function stressTestMixedLoad(): void
    {
        $operationCount = 60; // 20 each type
        
        // PDO Mixed Load
        $pdoStart = microtime(true);
        
        for ($i = 0; $i < $operationCount; $i++) {
            $operation = $i % 3;
            
            switch ($operation) {
                case 0: // Read
                    $stmt = $this->pdo->prepare("SELECT * FROM posts WHERE status = ? ORDER BY created_at DESC LIMIT 10");
                    $stmt->execute([['published', 'draft'][rand(0, 1)]]);
                    $stmt->fetchAll();
                    break;
                    
                case 1: // Write
                    $randomId = rand(1, 1000);
                    $stmt = $this->pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$randomId]);
                    break;
                    
                case 2: // Analytics
                    $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM posts WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
                    $stmt->fetch();
                    break;
            }
        }
        
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Mixed Load
        $asyncTime = run(function () use ($operationCount) {
            $start = microtime(true);
            
            $promises = [];
            
            for ($i = 0; $i < $operationCount; $i++) {
                $operation = $i % 3;
                
                switch ($operation) {
                    case 0: // Read
                        $promises[] = AsyncDB::table('posts')
                            ->where('status', '=', ['published', 'draft'][rand(0, 1)])
                            ->orderBy('created_at', 'DESC')
                            ->limit(10)
                            ->get();
                        break;
                        
                    case 1: // Write
                        $randomId = rand(1, 1000);
                        $promises[] = AsyncDB::table('posts')
                            ->where('id', '=', $randomId)
                            ->update(['updated_at' => date('Y-m-d H:i:s')]);
                        break;
                        
                    case 2: // Analytics
                        $promises[] = AsyncDB::table('posts')
                            ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-7 days')))
                            ->count();
                        break;
                }
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("Mixed Load Simulation ($operationCount operations)", $pdoTime, $asyncTime);
    }
    
    private function stressTestHighFrequency(): void
    {
        $operationCount = 200;
        
        // PDO High Frequency
        $pdoStart = microtime(true);
        
        for ($i = 0; $i < $operationCount; $i++) {
            $randomId = rand(1, 500);
            $stmt = $this->pdo->prepare("SELECT id, title, status FROM posts WHERE id = ?");
            $stmt->execute([$randomId]);
            $stmt->fetch();
        }
        
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB High Frequency
        $asyncTime = run(function () use ($operationCount) {
            $start = microtime(true);
            
            $promises = [];
            for ($i = 0; $i < $operationCount; $i++) {
                $randomId = rand(1, 500);
                $promises[] = AsyncDB::table('posts')
                    ->select(['id', 'title', 'status'])
                    ->where('id', '=', $randomId)
                    ->first();
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("High Frequency Operations ($operationCount quick queries)", $pdoTime, $asyncTime);
    }
    
    private function setupTestData(): void
    {
        // Use AsyncDB to setup data faster
        run(function () {
            $promises = [];
            foreach ($this->testData as $data) {
                $promises[] = AsyncDB::table('posts')->create($data);
            }
            await(all($promises));
        });
        
        echo "✓ Inserted {$this->recordCount} test records\n";
    }
    
    private function testHighConcurrencyReads(): void
    {
        $queryCount = 20;
        
        // PDO Sequential (can't do true concurrency)
        $pdoStart = microtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $status = ['published', 'draft'][$i % 2];
            $stmt = $this->pdo->prepare("SELECT * FROM posts WHERE status = ? ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$status]);
            $stmt->fetchAll();
        }
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Concurrent
        $asyncTime = run(function () use ($queryCount) {
            $start = microtime(true);
            
            $promises = [];
            for ($i = 0; $i < $queryCount; $i++) {
                $status = ['published', 'draft'][$i % 2];
                $promises[] = AsyncDB::table('posts')
                    ->where('status', '=', $status)
                    ->orderBy('created_at', 'DESC')
                    ->limit(5)
                    ->get();
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("High Concurrency Reads ($queryCount queries)", $pdoTime, $asyncTime);
    }
    
    private function testMixedOperationsWithDelays(): void
    {
        // PDO with simulated delays
        $pdoStart = microtime(true);
        $operations = [
            'SELECT COUNT(*) FROM posts WHERE status = "published"',
            'SELECT * FROM posts ORDER BY created_at DESC LIMIT 10',
            'SELECT status, COUNT(*) as count FROM posts GROUP BY status',
            'SELECT * FROM posts WHERE title LIKE "%Benchmark%" LIMIT 5',
            'SELECT AVG(CHAR_LENGTH(content)) as avg_length FROM posts'
        ];
        
        foreach ($operations as $query) {
            usleep(1000); // Simulate 1ms network delay
            $stmt = $this->pdo->query($query);
            $stmt->fetchAll();
        }
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB with simulated delays
        $asyncTime = run(function () {
            $start = microtime(true);
            
            $promises = [
                async(function () {
                    await(delay(0.001)); // 1ms delay
                    return await(AsyncDB::table('posts')->where('status', '=', 'published')->count());
                })(),
                
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->orderBy('created_at', 'DESC')->limit(10)->get());
                })(),
                
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->select(['status', 'COUNT(*) as count'])->groupBy('status')->get());
                })(),
                
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->where('title', 'LIKE', '%Benchmark%')->limit(5)->get());
                })(),
                
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->select(['AVG(CHAR_LENGTH(content)) as avg_length'])->get());
                })()
            ];
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison('Mixed Operations with Delays', $pdoTime, $asyncTime);
    }
    
    private function testDashboardQueries(): void
    {
        // PDO Dashboard Queries
        $pdoStart = microtime(true);
        $dashboardQueries = [
            'SELECT COUNT(*) as total_posts FROM posts',
            'SELECT COUNT(*) as published_posts FROM posts WHERE status = "published"',
            'SELECT COUNT(*) as draft_posts FROM posts WHERE status = "draft"',
            'SELECT * FROM posts ORDER BY created_at DESC LIMIT 5',
            'SELECT DATE(created_at) as date, COUNT(*) as count FROM posts GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 7',
            'SELECT status, AVG(CHAR_LENGTH(content)) as avg_content_length FROM posts GROUP BY status'
        ];
        
        foreach ($dashboardQueries as $query) {
            $stmt = $this->pdo->query($query);
            $stmt->fetchAll();
        }
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Dashboard Queries
        $asyncTime = run(function () {
            $start = microtime(true);
            
            $promises = [
                AsyncDB::table('posts')->count(),
                AsyncDB::table('posts')->where('status', '=', 'published')->count(),
                AsyncDB::table('posts')->where('status', '=', 'draft')->count(),
                AsyncDB::table('posts')->orderBy('created_at', 'DESC')->limit(5)->get(),
                AsyncDB::table('posts')
                    ->select(['DATE(created_at) as date', 'COUNT(*) as count'])
                    ->groupBy('DATE(created_at)')
                    ->orderBy('date', 'DESC')
                    ->limit(7)
                    ->get(),
                AsyncDB::table('posts')
                    ->select(['status', 'AVG(CHAR_LENGTH(content)) as avg_content_length'])
                    ->groupBy('status')
                    ->get()
            ];
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison('Dashboard Analytics (6 concurrent queries)', $pdoTime, $asyncTime);
    }
    
    private function testBatchProcessing(): void
    {
        $batchSize = 20;
        
        // PDO Batch Processing
        $pdoStart = microtime(true);
        $stmt = $this->pdo->query("SELECT id, title, status FROM posts LIMIT $batchSize");
        $posts = $stmt->fetchAll();
        
        foreach ($posts as $post) {
            // Simulate processing each post
            $updateStmt = $this->pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$post['id']]);
            
            // Simulate logging
            $logStmt = $this->pdo->prepare("SELECT COUNT(*) FROM posts WHERE status = ?");
            $logStmt->execute([$post['status']]);
        }
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Batch Processing
        $asyncTime = run(function () use ($batchSize) {
            $start = microtime(true);
            
            $posts = await(AsyncDB::table('posts')
                ->select(['id', 'title', 'status'])
                ->limit($batchSize)
                ->get());
            
            $promises = [];
            foreach ($posts as $post) {
                $promises[] = async(function () use ($post) {
                    // Process each post concurrently
                    await(AsyncDB::table('posts')
                        ->where('id', '=', $post['id'])
                        ->update(['updated_at' => date('Y-m-d H:i:s')]));
                    
                    // Simulate logging
                    await(AsyncDB::table('posts')
                        ->where('status', '=', $post['status'])
                        ->count());
                })();
            }
            
            await(all($promises));
            return microtime(true) - $start;
        });
        
        $this->printComparison("Batch Processing ($batchSize records)", $pdoTime, $asyncTime);
    }
    
    private function testRealWorldScenario(): void
    {
        // PDO Real-world Scenario
        $pdoStart = microtime(true);
        
        // Get featured posts
        $stmt = $this->pdo->query("SELECT * FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 3");
        $featuredPosts = $stmt->fetchAll();
        
        // Get post counts by status
        $stmt = $this->pdo->query("SELECT status, COUNT(*) as count FROM posts GROUP BY status");
        $statusCounts = $stmt->fetchAll();
        
        // Get recent posts
        $stmt = $this->pdo->query("SELECT * FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 10");
        $recentPosts = $stmt->fetchAll();
        
        // Get stats
        $stmt = $this->pdo->query("SELECT COUNT(*) as total, AVG(CHAR_LENGTH(content)) as avg_length FROM posts");
        $stats = $stmt->fetch();
        
        // Get posts by month
        $stmt = $this->pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count FROM posts GROUP BY month ORDER BY month DESC LIMIT 12");
        $monthlyStats = $stmt->fetchAll();
        
        $pdoTime = microtime(true) - $pdoStart;
        
        // AsyncDB Real-world Scenario
        $asyncTime = run(function () {
            $start = microtime(true);
            
            $promises = [
                // All these queries can run concurrently
                AsyncDB::table('posts')
                    ->where('status', '=', 'published')
                    ->orderBy('created_at', 'DESC')
                    ->limit(3)
                    ->get(),
                
                AsyncDB::table('posts')
                    ->select(['status', 'COUNT(*) as count'])
                    ->groupBy('status')
                    ->get(),
                
                AsyncDB::table('posts')
                    ->where('status', '=', 'published')
                    ->orderBy('created_at', 'DESC')
                    ->limit(10)
                    ->get(),
                
                AsyncDB::table('posts')
                    ->select(['COUNT(*) as total', 'AVG(CHAR_LENGTH(content)) as avg_length'])
                    ->get(),
                
                AsyncDB::table('posts')
                    ->select(['DATE_FORMAT(created_at, "%Y-%m") as month', 'COUNT(*) as count'])
                    ->groupBy('month')
                    ->orderBy('month', 'DESC')
                    ->limit(12)
                    ->get()
            ];
            
            list($featured, $statusCounts, $recent, $stats, $monthly) = await(all($promises));
            
            return microtime(true) - $start;
        });
        
        $this->printComparison('Real-world Scenario (Blog Page Load)', $pdoTime, $asyncTime);
    }
    
    private function printComparison(string $testName, float $pdoTime, float $asyncTime): void
    {
        $pdoMs = round($pdoTime * 1000, 2);
        $asyncMs = round($asyncTime * 1000, 2);
        $difference = round((($asyncTime - $pdoTime) / $pdoTime) * 100, 1);
        
        echo "   PDO:      {$pdoMs}ms\n";
        echo "   AsyncDB:  {$asyncMs}ms\n";
        
        if ($difference > 0) {
            echo "   Result:   AsyncDB is {$difference}% slower\n";
        } else {
            echo "   Result:   AsyncDB is " . abs($difference) . "% faster\n";
        }
        
        // Add performance insight
        if ($difference < -50) {
            echo "   🚀 Massive async advantage!\n";
        } elseif ($difference < -20) {
            echo "   💡 Significant async advantage!\n";
        } elseif ($difference < -10) {
            echo "   ✅ Good async performance\n";
        } elseif ($difference < 10) {
            echo "   ➡️ Comparable performance\n";
        } else {
            echo "   ⚠️ Async overhead visible\n";
        }
        
        echo "   ---\n";
    }
    
    private function cleanupTestData(): void
    {
        $this->pdo->exec("DELETE FROM posts WHERE title LIKE '%Benchmark%'");
    }
    
    private function cleanupStressTestData(): void
    {
        $this->pdo->exec("DELETE FROM posts WHERE title LIKE '%Stress Test%'");
    }
}

try {
    $benchmark = new EnhancedDatabaseBenchmark();
    $benchmark->runBenchmark();
} catch (Exception $e) {
    echo "Benchmark failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}