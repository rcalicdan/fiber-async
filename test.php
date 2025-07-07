<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\AsyncDB;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

class AsyncDatabaseBenchmark
{
    private PDO $pdo;
    private array $testData = [];
    private int $recordCount = 500;
    private int $stressTestRecordCount = 2000;

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
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->prepareTestData();
    }

    private function prepareTestData(): void
    {
        for ($i = 1; $i <= $this->recordCount; $i++) {
            $this->testData[] = [
                'title' => "Benchmark Post #$i",
                'content' => str_repeat("Lorem ipsum dolor sit amet. ", 20),
                'status' => $i % 2 === 0 ? 'draft' : 'published',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    private function prepareStressTestData(): array
    {
        $data = [];
        for ($i = 1; $i <= $this->stressTestRecordCount; $i++) {
            $data[] = [
                'title' => "Stress Post #$i",
                'content' => str_repeat("Stress content ", 50),
                'status' => $i % 2 === 0 ? 'draft' : 'published',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }
        return $data;
    }

    public function run(): void
    {
        echo "=== ASYNC DATABASE BENCHMARK ===\n\n";

        $this->setupDatabaseSchema();

        // --- INSERT TESTS ---
        $this->testStressInsertTransactional();
        $this->testStressInsertNaive(); //

        // --- READ & MIXED TESTS ---
        $this->setupTestData(); // Set up a consistent dataset for read tests
        $this->testHighConcurrencyReads();
        $this->testHighConcurrencyReadsWithLimit();
        $this->testMixedOperationsWithDelays();

        $this->cleanup(true); 
    }

    /**
     * Test: The CORRECT way to do bulk inserts with both PDO and AsyncDB.
     */
    private function testStressInsertTransactional(): void
    {
        $data = $this->prepareStressTestData();
        echo "--- Testing Bulk Inserts ({$this->stressTestRecordCount} records) inside a TRANSACTION ---\n";
        echo "(This is the correct and most efficient pattern for bulk inserts)\n";

        // PDO Transactional Insert
        $pdoStart = hrtime(true);
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("INSERT INTO posts (title, content, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($data as $row) {
            $stmt->execute(array_values($row));
        }
        $this->pdo->commit();
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;
        $this->cleanup();

        // AsyncDB Transactional Insert
        $asyncStart = hrtime(true);
        run(async(function () use ($data) {
            AsyncDB::beginTransaction();
            $promises = [];
            foreach ($data as $row) {
                $promises[] = AsyncDB::table('posts')->create($row);
            }
            await(all($promises));
            AsyncDB::commit();
        }));
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;
        $this->cleanup();

        $this->printComparison("Transactional Insert", $pdoTime, $asyncTime);
    }

    /**
     * Test: The NAIVE way to do bulk inserts (one query per record).
     * This test now includes PDO for a direct comparison of the anti-pattern.
     */
    private function testStressInsertNaive(): void
    {
        $naiveRecordCount = 200; // Using a smaller count because this is extremely slow.
        $data = array_slice($this->prepareStressTestData(), 0, $naiveRecordCount);

        echo "\n--- Testing Bulk Inserts ({$naiveRecordCount} records) with NAIVE individual queries ---\n";
        echo "(This is an anti-pattern and demonstrates the high cost of network round-trips)\n";

        // PDO Naive Insert (one query per record)
        $pdoStart = hrtime(true);
        $stmt = $this->pdo->prepare("INSERT INTO posts (title, content, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($data as $row) {
            $stmt->execute(array_values($row));
        }
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;
        $this->cleanup();

        // AsyncDB Naive Insert (one query per record)
        $asyncStart = hrtime(true);
        run(async(function () use ($data) {
            $promises = [];
            foreach ($data as $row) {
                $promises[] = AsyncDB::table('posts')->create($row);
            }
            await(all($promises));
        }));
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;
        $this->cleanup();

        $this->printComparison("Naive Individual Inserts", $pdoTime, $asyncTime);
    }

    /**
     * Test: High concurrency reads where all queries are sent at once.
     */
    private function testHighConcurrencyReads(): void
    {
        $queryCount = 100;
        echo "\n--- Testing High Concurrency Reads ({$queryCount} simultaneous queries) ---\n";
        echo "(This scenario is where async I/O provides the most benefit)\n";

        // PDO Sequential Reads
        $pdoStart = hrtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'");
            $stmt->fetchAll();
        }
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

        // AsyncDB Concurrent Reads
        $asyncStart = hrtime(true);
        run(async(function () use ($queryCount) {
            $promises = [];
            for ($i = 0; $i < $queryCount; $i++) {
                $promises[] = AsyncDB::table('posts')->where('status', '=', 'published')->count();
            }
            await(all($promises));
        }));
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;

        $this->printComparison("High Concurrency Reads", $pdoTime, $asyncTime);
    }

    /**
     * Test: Realistic concurrency with a limit to avoid overwhelming the system.
     */
    private function testHighConcurrencyReadsWithLimit(): void
    {
        $queryCount = 100;
        $concurrencyLimit = 10;
        echo "\n--- Testing Reads with a Concurrency Limit of {$concurrencyLimit} ---\n";
        echo "(A practical use case for processing many tasks safely)\n";

        // PDO Sequential baseline
        $pdoStart = hrtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $this->pdo->query("SELECT COUNT(*) FROM posts")->fetchAll();
        }
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

        // AsyncDB Concurrent Reads with a limit
        $asyncStart = hrtime(true);
        $tasks = [];
        for ($i = 0; $i < $queryCount; $i++) {
            $tasks[] = fn() => AsyncDB::table('posts')->count();
        }
        run_concurrent($tasks, $concurrencyLimit);
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;

        $this->printComparison("Limited Concurrency Reads", $pdoTime, $asyncTime);
    }

    /**
     * Test: A mix of different queries with simulated application logic delay.
     */
    private function testMixedOperationsWithDelays(): void
    {
        echo "\n--- Testing mixed operations with simulated 1ms delays ---\n";

        // PDO with delays
        $pdoStart = hrtime(true);
        usleep(1000);
        $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchAll();
        usleep(1000);
        $this->pdo->query('SELECT * FROM posts ORDER BY created_at DESC LIMIT 5')->fetchAll();
        usleep(1000);
        $this->pdo->query('SELECT AVG(CHAR_LENGTH(content)) FROM posts')->fetchAll();
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

        // AsyncDB with delays
        $asyncStart = hrtime(true);
        run(async(function () {
            await(all([
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->count());
                })(),
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->orderBy('created_at', 'DESC')->limit(5)->get());
                })(),
                async(function () {
                    await(delay(0.001));
                    return await(AsyncDB::table('posts')->select(['AVG(CHAR_LENGTH(content))'])->get());
                })(),
            ]));
        }));
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;

        $this->printComparison("Simulated Delay Ops", $pdoTime, $asyncTime);
    }

    private function setupDatabaseSchema(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS posts");
        $this->pdo->exec("
            CREATE TABLE posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                status ENUM('draft', 'published') NOT NULL,
                created_at DATETIME,
                updated_at DATETIME
            )
        ");
        echo "✓ Database schema initialized.\n";
    }

    private function setupTestData(): void
    {
        $this->cleanup();
        run(async(function () {
            AsyncDB::beginTransaction();
            $promises = [];
            foreach ($this->testData as $row) {
                $promises[] = AsyncDB::table('posts')->create($row);
            }
            await(all($promises));
            AsyncDB::commit();
        }));
        echo "✓ Inserted {$this->recordCount} test records for read tests.\n";
    }

    private function cleanup(bool $final = false): void
    {
        $this->pdo->exec("DELETE FROM posts");
        if ($final) {
            echo "✓ Final cleanup complete.\n";
        }
    }

    private function printComparison(string $label, float $pdoTime, float $asyncTime): void
    {
        $pdoMs = round($pdoTime * 1000, 2);
        $asyncMs = round($asyncTime * 1000, 2);
        $timeDiff = $pdoMs - $asyncMs;

        echo "\n* $label *\n";
        echo "   PDO (Sync):   {$pdoMs} ms\n";
        echo "   AsyncDB:      {$asyncMs} ms\n";

        if ($timeDiff > 5) {
            $speedup = ($asyncMs > 0) ? round($pdoMs / $asyncMs, 2) : 'inf';
            echo "   ✅ Async is faster by {$timeDiff} ms ({$speedup}x)\n";
        } elseif ($timeDiff < -5) {
            echo "   ⚠️ Async is slower by " . abs($timeDiff) . " ms\n";
        } else {
            echo "   ➡️ Performance is comparable\n";
        }
        echo "   ---\n";
    }
}

try {
    (new AsyncDatabaseBenchmark())->run();
} catch (Exception $e) {
    echo "\nBenchmark failed: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
}
