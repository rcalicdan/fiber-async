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

    /**
     * MODIFIED: Added a pause before the final cleanup.
     */
    public function run(): void
    {
        echo "=== ASYNC DATABASE BENCHMARK ===\n\n";

        $this->setupDatabaseSchema();

        // --- RUN SELF-CONTAINED TESTS FIRST ---
        $this->testStressInsertNaive();

        // --- RUN READ TESTS ON A CONTROLLED DATASET ---
        $this->setupTestData();
        $this->testHighConcurrencyReads();
        $this->testHighConcurrencyReadsWithLimit();
        $this->testMixedOperationsWithDelays();

        // --- RUN THE SPECIAL TRANSACTIONAL TEST LAST ---
        $this->testStressInsertTransactional();

        // --- PAUSE SCRIPT TO ALLOW FOR MANUAL VERIFICATION ---
        echo "\n\n-------------------------------------------------------------\n";
        echo "PAUSED: The database now contains the final test data.\n";
        echo "Check your MySQL client to verify the 'Sync:' and 'Async:' posts.\n";
        readline("Press [Enter] to continue and clear the database...");
        echo "-------------------------------------------------------------\n\n";

        // The final cleanup will now run only after the user presses Enter.
        $this->cleanup(true, "Data from all tests has been removed.");
    }

    /**
     * This test now runs last, verifies inserts, and does NOT clean up internally.
     */
    private function testStressInsertTransactional(): void
    {
        $data = $this->prepareStressTestData();
        $expectedCount = count($data);

        echo "\n--- Testing Bulk Inserts ({$expectedCount} records) inside a TRANSACTION ---\n";
        echo "NOTE: Data from this test will be left in the database until the script finishes.\n";

        $this->cleanup();

        // --- PDO Transactional Insert with "Sync:" prefix and verification ---
        $pdoData = array_map(function ($row) {
            $row['title'] = "Sync: " . $row['title'];
            return $row;
        }, $data);

        $pdoStart = hrtime(true);
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("INSERT INTO posts (title, content, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($pdoData as $row) {
            $stmt->execute(array_values($row));
        }
        $this->pdo->commit();

        $insertedCount = (int) $this->pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
        if ($insertedCount !== $expectedCount) {
            throw new Exception("PDO insert verification failed! Expected {$expectedCount}, but found {$insertedCount}.");
        }
        echo "   -> PDO Verification: OK ({$insertedCount} records inserted with 'Sync:' prefix)\n";
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

        // --- AsyncDB Transactional Insert with "Async:" prefix and verification ---
        $asyncStart = hrtime(true);
        run(async(function () use ($data, $expectedCount) {
            $asyncData = array_map(function ($row) {
                $row['title'] = "Async: " . $row['title'];
                return $row;
            }, $data);

            AsyncDB::beginTransaction();
            $promises = [];
            foreach ($asyncData as $row) {
                $promises[] = AsyncDB::table('posts')->create($row);
            }
            await(all($promises));
            AsyncDB::commit();

            $totalExpectedCount = $expectedCount * 2;
            $totalInsertedCount = await(AsyncDB::table('posts')->count());
            if ($totalInsertedCount !== $totalExpectedCount) {
                throw new Exception("AsyncDB insert verification failed! Expected total {$totalExpectedCount}, but found {$totalInsertedCount}.");
            }
            echo "   -> AsyncDB Verification: OK (Total records now {$totalInsertedCount})\n";
        }));
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;

        echo "\n   -> You can now manually check the 'posts' table for 'Sync:' and 'Async:' prefixed titles.\n";

        $this->printComparison("Transactional Insert", $pdoTime, $asyncTime);
    }
    
    // ... all other methods remain exactly the same ...

    private function testStressInsertNaive(): void
    {
        $naiveRecordCount = 200;
        $data = array_slice($this->prepareStressTestData(), 0, $naiveRecordCount);

        echo "\n--- Testing Bulk Inserts ({$naiveRecordCount} records) with NAIVE individual queries ---\n";
        echo "(This is an anti-pattern and demonstrates the high cost of network round-trips)\n";

        $pdoStart = hrtime(true);
        $stmt = $this->pdo->prepare("INSERT INTO posts (title, content, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($data as $row) {
            $stmt->execute(array_values($row));
        }
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;
        $this->cleanup();

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

    private function testHighConcurrencyReads(): void
    {
        $queryCount = 100;
        echo "\n--- Testing High Concurrency Reads ({$queryCount} simultaneous queries) ---\n";
        echo "(This scenario is where async I/O provides the most benefit)\n";

        $pdoStart = hrtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'");
            $stmt->fetchAll();
        }
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

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

    private function testHighConcurrencyReadsWithLimit(): void
    {
        $queryCount = 100;
        $concurrencyLimit = 10;
        echo "\n--- Testing Reads with a Concurrency Limit of {$concurrencyLimit} ---\n";
        echo "(A practical use case for processing many tasks safely)\n";

        $pdoStart = hrtime(true);
        for ($i = 0; $i < $queryCount; $i++) {
            $this->pdo->query("SELECT COUNT(*) FROM posts")->fetchAll();
        }
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

        $asyncStart = hrtime(true);
        $tasks = [];
        for ($i = 0; $i < $queryCount; $i++) {
            $tasks[] = fn() => AsyncDB::table('posts')->count();
        }
        run_concurrent($tasks, $concurrencyLimit);
        $asyncTime = (hrtime(true) - $asyncStart) / 1e9;

        $this->printComparison("Limited Concurrency Reads", $pdoTime, $asyncTime);
    }

    private function testMixedOperationsWithDelays(): void
    {
        echo "\n--- Testing mixed operations with simulated 1ms delays ---\n";

        $pdoStart = hrtime(true);
        usleep(1000);
        $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchAll();
        usleep(1000);
        $this->pdo->query('SELECT * FROM posts ORDER BY created_at DESC LIMIT 5')->fetchAll();
        usleep(1000);
        $this->pdo->query('SELECT AVG(CHAR_LENGTH(content)) FROM posts')->fetchAll();
        $pdoTime = (hrtime(true) - $pdoStart) / 1e9;

        $asyncStart = hrtime(true);
        run(async(function () {
            await(all([
                async(function () { await(delay(0.001)); return await(AsyncDB::table('posts')->count()); })(),
                async(function () { await(delay(0.001)); return await(AsyncDB::table('posts')->orderBy('created_at', 'DESC')->limit(5)->get()); })(),
                async(function () { await(delay(0.001)); return await(AsyncDB::table('posts')->select(['AVG(CHAR_LENGTH(content))'])->get()); })(),
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

    private function cleanup(bool $final = false, string $message = ''): void
    {
        $this->pdo->exec("DELETE FROM posts");
        if ($final) {
            echo "✓ " . ($message ?: "Final cleanup complete.") . "\n";
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