<?php

use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\PDO\AsyncPdoPool;

require "vendor/autoload.php";

class PdoPoolTester
{
    private array $memorySnapshots = [];
    private int $testCounter = 0;

    public function logMemory(string $label): void
    {
        $this->memorySnapshots[] = [
            'label' => $label,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'real_usage' => memory_get_usage(false),
            'real_peak' => memory_get_peak_usage(false),
            'time' => microtime(true)
        ];
    }

    public function printMemoryReport(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "PDO POOL MEMORY USAGE REPORT\n";
        echo str_repeat("=", 80) . "\n";
        
        printf("%-30s | %-12s | %-12s | %-12s | %-12s\n", 
            "Checkpoint", "Memory (KB)", "Peak (KB)", "Real (KB)", "Real Peak (KB)"
        );
        echo str_repeat("-", 80) . "\n";
        
        foreach ($this->memorySnapshots as $snapshot) {
            printf("%-30s | %12d | %12d | %12d | %12d\n",
                $snapshot['label'],
                round($snapshot['memory_usage'] / 1024),
                round($snapshot['memory_peak'] / 1024),
                round($snapshot['real_usage'] / 1024),
                round($snapshot['real_peak'] / 1024)
            );
        }
        
        // Calculate memory growth
        $first = reset($this->memorySnapshots);
        $last = end($this->memorySnapshots);
        $growth = $last['memory_usage'] - $first['memory_usage'];
        $realGrowth = $last['real_usage'] - $first['real_usage'];
        
        echo str_repeat("-", 80) . "\n";
        printf("Total Memory Growth: %d KB (Real: %d KB)\n", 
            round($growth / 1024), 
            round($realGrowth / 1024)
        );
        
        if ($growth > 1024 * 1024) { // More than 1MB growth
            echo "⚠️  WARNING: Significant memory growth detected! Possible memory leak.\n";
        } else {
            echo "✅ Memory usage looks normal.\n";
        }
        echo str_repeat("=", 80) . "\n\n";
    }

    public function testOneTimeValidation(): void
    {
        echo "🧪 TESTING ONE-TIME VALIDATION\n";
        echo str_repeat("-", 50) . "\n";
        
        $this->logMemory("Before pool creation");
        
        // Create a custom pool class that extends AsyncPdoPool
        $testPool = new class([
            'driver' => 'mysql', 
            'host' => 'localhost', 
            'username' => 'test', 
            'database' => 'test'
        ], 5) extends AsyncPdoPool {
            public static int $constructorCallCount = 0;
            
            public function __construct(array $dbConfig, int $maxSize = 10)
            {
                self::$constructorCallCount++;
                echo "🔍 Constructor called! Count: " . self::$constructorCallCount . "\n";
                parent::__construct($dbConfig, $maxSize);
            }
            
            public static function getConstructorCallCount(): int
            {
                return self::$constructorCallCount;
            }
            
            public static function resetConstructorCount(): void
            {
                self::$constructorCallCount = 0;
            }
        };
        
        $this->logMemory("After pool creation");
        
        echo "Pool created. Constructor calls: " . $testPool::getConstructorCallCount() . "\n";
        
        // Test that the configuration validation flag is set
        $stats = $testPool->getStats();
        echo "Config validated flag: " . ($stats['config_validated'] ? 'true' : 'false') . "\n";
        
        // Get stats multiple times to ensure the flag remains consistent
        for ($i = 1; $i <= 5; $i++) {
            $stats = $testPool->getStats();
            echo "Stats call #{$i}: config_validated = " . ($stats['config_validated'] ? 'true' : 'false') . "\n";
        }
        
        if ($testPool::getConstructorCallCount() === 1 && $stats['config_validated']) {
            echo "✅ SUCCESS: Configuration validated exactly once during construction!\n";
        } else {
            echo "❌ FAILED: Constructor called " . $testPool::getConstructorCallCount() . " times!\n";
        }
        
        $testPool->close();
        $this->logMemory("After pool close");
        echo "\n";
    }

    public function testDriverSpecificValidation(): void
    {
        echo "🧪 TESTING DRIVER-SPECIFIC VALIDATION\n";
        echo str_repeat("-", 50) . "\n";
        
        $this->logMemory("Before driver validation tests");
        
        $validConfigs = [
            'MySQL' => [
                'driver' => 'mysql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'test',
            ],
            'PostgreSQL' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'test',
            ],
            'SQLite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
            'SQL Server' => [
                'driver' => 'sqlsrv',
                'host' => 'localhost',
                'username' => 'test',
            ],
            'Oracle' => [
                'driver' => 'oci',
                'database' => 'XE',
                'username' => 'test',
            ],
        ];
        
        foreach ($validConfigs as $driverName => $config) {
            try {
                echo "Testing {$driverName} valid config... ";
                $pool = new AsyncPdoPool($config, 3);
                $stats = $pool->getStats();
                echo "✅ SUCCESS - config_validated: " . ($stats['config_validated'] ? 'true' : 'false') . "\n";
                $pool->close();
            } catch (\Exception $e) {
                echo "❌ FAILED: " . $e->getMessage() . "\n";
            }
        }
        
        $this->logMemory("After driver validation tests");
        echo "\n";
    }

    public function testValidationErrors(): void
    {
        echo "🧪 TESTING PDO VALIDATION ERROR HANDLING\n";
        echo str_repeat("-", 50) . "\n";
        
        $this->logMemory("Before validation error tests");
        
        $testCases = [
            // General validation errors
            'Empty config' => [],
            'Missing driver' => ['host' => 'localhost', 'database' => 'test'],
            'Empty driver' => ['driver' => '', 'host' => 'localhost', 'database' => 'test'],
            'Invalid driver type' => ['driver' => 123, 'host' => 'localhost', 'database' => 'test'],
            'Unsupported driver' => ['driver' => 'nosql', 'host' => 'localhost', 'database' => 'test'],
            
            // MySQL specific errors
            'MySQL missing host' => ['driver' => 'mysql', 'database' => 'test', 'username' => 'test'],
            'MySQL missing database' => ['driver' => 'mysql', 'host' => 'localhost', 'username' => 'test'],
            'MySQL empty host' => ['driver' => 'mysql', 'host' => '', 'database' => 'test', 'username' => 'test'],
            'MySQL empty database' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => '', 'username' => 'test'],
            
            // PostgreSQL specific errors
            'PostgreSQL missing host' => ['driver' => 'pgsql', 'database' => 'test', 'username' => 'test'],
            'PostgreSQL missing database' => ['driver' => 'pgsql', 'host' => 'localhost', 'username' => 'test'],
            
            // SQLite specific errors
            'SQLite missing database' => ['driver' => 'sqlite', 'host' => 'localhost'],
            'SQLite empty database' => ['driver' => 'sqlite', 'database' => ''],
            
            // SQL Server specific errors
            'SQL Server missing host' => ['driver' => 'sqlsrv', 'database' => 'test', 'username' => 'test'],
            'SQL Server empty host' => ['driver' => 'sqlsrv', 'host' => '', 'database' => 'test', 'username' => 'test'],
            
            // Oracle specific errors
            'Oracle missing database' => ['driver' => 'oci', 'host' => 'localhost', 'username' => 'test'],
            'Oracle empty database' => ['driver' => 'oci', 'database' => '', 'host' => 'localhost', 'username' => 'test'],
            
            // Common field type errors
            'Invalid port (string)' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'port' => 'invalid'],
            'Invalid port (negative)' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'port' => -1],
            'Invalid port (zero)' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'port' => 0],
            'Invalid host type' => ['driver' => 'mysql', 'host' => 123, 'database' => 'test'],
            'Invalid username type' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 123],
            'Invalid password type' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'password' => 123],
            'Invalid charset type' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'charset' => 123],
            'Invalid options type' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'options' => 'invalid'],
            
            // IBM DB2/ODBC specific errors
            'IBM DB2 missing database and dsn' => ['driver' => 'ibm', 'host' => 'localhost'],
            'ODBC missing database and dsn' => ['driver' => 'odbc', 'host' => 'localhost'],
        ];
        
        foreach ($testCases as $testName => $config) {
            try {
                echo "Testing: {$testName}... ";
                $pool = new AsyncPdoPool($config);
                echo "❌ FAILED - Should have thrown exception\n";
                $pool->close();
            } catch (\InvalidArgumentException $e) {
                echo "✅ PASSED - " . $e->getMessage() . "\n";
            } catch (\Throwable $e) {
                echo "⚠️  UNEXPECTED - " . get_class($e) . ": " . $e->getMessage() . "\n";
            }
        }
        
        $this->logMemory("After validation error tests");
        echo "\n";
    }

    public function testMemoryLeaks(): void
    {
        echo "🧪 TESTING PDO MEMORY LEAKS\n";
        echo str_repeat("-", 50) . "\n";
        
        $this->logMemory("Before memory leak test");
        
        // Test 1: Create and destroy many pools with different drivers
        echo "Test 1: Creating and destroying 100 pools with various drivers...\n";
        $drivers = [
            ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test1'],
            ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test2'],
            ['driver' => 'sqlite', 'database' => ':memory:'],
            ['driver' => 'sqlsrv', 'host' => 'localhost'],
            ['driver' => 'oci', 'database' => 'XE'],
        ];
        
        for ($i = 1; $i <= 100; $i++) {
            $config = $drivers[$i % count($drivers)];
            $config['database'] = ($config['database'] ?? 'test') . $i;
            
            $pool = new AsyncPdoPool($config, 3);
            $stats = $pool->getStats();
            $pool->close();
            unset($pool);
            
            if ($i % 20 === 0) {
                $this->logMemory("After {$i} PDO pools created/destroyed");
                gc_collect_cycles();
            }
        }
        
        $this->logMemory("After pool creation test");
        
        // Test 2: Test intensive stats operations
        echo "Test 2: Testing pool operations without DB connections...\n";
        $pool = new AsyncPdoPool([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 10);
        
        for ($i = 1; $i <= 1000; $i++) {
            $stats = $pool->getStats();
            
            if ($i % 250 === 0) {
                $this->logMemory("After {$i} PDO stats calls");
                gc_collect_cycles();
            }
        }
        
        $pool->close();
        $this->logMemory("After pool operations test");
        
        // Test 3: Test rapid pool creation/destruction cycles
        echo "Test 3: Testing rapid pool close/recreate cycles...\n";
        for ($i = 1; $i <= 50; $i++) {
            $pool = new AsyncPdoPool([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ], 5);
            
            // Get stats multiple times
            for ($j = 1; $j <= 10; $j++) {
                $stats = $pool->getStats();
            }
            
            $pool->close();
            unset($pool);
            
            if ($i % 10 === 0) {
                $this->logMemory("After {$i} PDO pool cycles");
                gc_collect_cycles();
            }
        }
        
        // Test 4: Force garbage collection
        echo "Test 4: Running garbage collection...\n";
        $cycles = gc_collect_cycles();
        echo "Garbage collector freed {$cycles} cycles\n";
        
        $this->logMemory("After garbage collection");
        
        echo "✅ PDO memory leak test completed\n\n";
    }

    public function testAsyncPDOInit(): void
    {
        echo "🧪 TESTING AsyncPDO::init() METHOD\n";
        echo str_repeat("-", 50) . "\n";
        
        $this->logMemory("Before AsyncPDO init tests");
        
        // Test 1: Test with empty config (should fail)
        echo "Test 1: AsyncPDO::init() with empty config...\n";
        try {
            AsyncPDO::init([]);
            echo "❌ FAILED - Should have thrown exception\n";
        } catch (\Exception $e) {
            echo "✅ PASSED - " . $e->getMessage() . "\n";
        }
        
        // Test 2: Test with valid config
        echo "Test 2: AsyncPDO::init() with valid SQLite config...\n";
        try {
            AsyncPDO::reset(); // Reset first
            AsyncPDO::init([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ]);
            echo "✅ SUCCESS: AsyncPDO initialized successfully\n";
        } catch (\Exception $e) {
            echo "❌ FAILED: " . $e->getMessage() . "\n";
        }
        
        // Test 3: Test double initialization
        echo "Test 3: Testing double initialization (should be ignored)...\n";
        try {
            AsyncPDO::init([
                'driver' => 'mysql',
                'host' => 'localhost',
                'database' => 'different_db',
            ]);
            echo "✅ SUCCESS: Double initialization handled correctly\n";
        } catch (\Exception $e) {
            echo "⚠️  UNEXPECTED: " . $e->getMessage() . "\n";
        }
        
        // Test 4: Test reset and re-init
        echo "Test 4: Testing reset and re-initialization...\n";
        try {
            AsyncPDO::reset();
            AsyncPDO::init([
                'driver' => 'mysql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'test',
            ]);
            echo "✅ SUCCESS: Reset and re-initialization worked\n";
        } catch (\Exception $e) {
            echo "❌ FAILED: " . $e->getMessage() . "\n";
        }
        
        AsyncPDO::reset(); // Clean up
        $this->logMemory("After AsyncPDO init tests");
        echo "\n";
    }

    public function runAllTests(): void
    {
        echo "🚀 STARTING ASYNC PDO POOL TESTS\n";
        echo str_repeat("=", 80) . "\n";
        
        $this->logMemory("Test start");
        
        $this->testOneTimeValidation();
        $this->testDriverSpecificValidation();
        $this->testValidationErrors();
        $this->testAsyncPDOInit();
        $this->testMemoryLeaks();
        
        $this->logMemory("Test end");
        $this->printMemoryReport();
        
        echo "🎉 ALL PDO TESTS COMPLETED\n";
    }
}

// Enable memory leak detection
ini_set('memory_limit', '256M');
gc_enable();

// Run the tests
$tester = new PdoPoolTester();
$tester->runAllTests();

// Additional memory debugging info
echo "FINAL PDO MEMORY DEBUG INFO:\n";
echo str_repeat("-", 40) . "\n";
echo "Current memory usage: " . round(memory_get_usage(true) / 1024) . " KB\n";
echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024) . " KB\n";
echo "Real memory usage: " . round(memory_get_usage(false) / 1024) . " KB\n";
echo "Real peak usage: " . round(memory_get_peak_usage(false) / 1024) . " KB\n";
echo "GC Stats: " . json_encode(gc_status()) . "\n";