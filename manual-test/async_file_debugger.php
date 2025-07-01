<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

class AsyncFileDebugger
{
    private string $testDir;
    private array $operationLog = [];
    private int $operationCounter = 0;

    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/async_debug_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
        echo "Test directory: {$this->testDir}\n\n";
    }

    public function runComprehensiveDebug()
    {
        echo "🔍 COMPREHENSIVE ASYNC FILE DEBUG\n";
        echo str_repeat('=', 60) . "\n\n";

        // Test 1: Verify async methods exist
        $this->testAsyncMethodsExist();

        // Test 2: Single operation timing
        $this->testSingleOperationTiming();

        // Test 3: Sequential vs concurrent behavior
        $this->testSequentialVsConcurrent();

        // Test 4: Operation ordering verification
        $this->testOperationOrdering();

        // Test 5: File existence verification
        $this->testFileExistenceVerification();

        // Test 6: Error handling
        $this->testErrorHandling();

        // Test 7: Fiber context verification
        $this->testFiberContext();

        // Test 8: Resource cleanup verification
        $this->testResourceCleanup();

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "🏁 DEBUG COMPLETE - Check results above\n";
    }

    private function testAsyncMethodsExist()
    {
        echo "1️⃣ TESTING: Async Methods Existence\n";
        echo str_repeat('-', 40) . "\n";

        $methods = ['writeFile', 'readFile', 'deleteFile', 'fileExists', 'copyFile', 'delay', 'concurrent', 'async', 'await', 'run'];
        
        foreach ($methods as $method) {
            $exists = method_exists(Async::class, $method);
            $status = $exists ? "✅ EXISTS" : "❌ MISSING";
            echo "  Async::{$method}(): {$status}\n";
            
            if (!$exists && in_array($method, ['writeFile', 'readFile', 'deleteFile'])) {
                echo "    ⚠️  CRITICAL: Core file method missing!\n";
            }
        }
        echo "\n";
    }

    private function testSingleOperationTiming()
    {
        echo "2️⃣ TESTING: Single Operation Timing\n";
        echo str_repeat('-', 40) . "\n";

        $filename = $this->testDir . '/timing_test.txt';
        $content = str_repeat('X', 1000);

        // Test sync timing
        $syncStart = microtime(true);
        file_put_contents($filename, $content);
        $syncContent = file_get_contents($filename);
        unlink($filename);
        $syncTime = (microtime(true) - $syncStart) * 1000;

        echo "  Sync operation: " . number_format($syncTime, 3) . "ms\n";

        // Test async timing
        try {
            $asyncTime = Async::run(function () use ($filename, $content) {
                $start = microtime(true);
                
                echo "    Starting async write...\n";
                Async::await(Async::writeFile($filename, $content));
                echo "    Async write completed\n";
                
                echo "    Starting async read...\n";
                $readContent = Async::await(Async::readFile($filename));
                echo "    Async read completed (length: " . strlen($readContent) . ")\n";
                
                echo "    Starting async delete...\n";
                Async::await(Async::deleteFile($filename));
                echo "    Async delete completed\n";
                
                return (microtime(true) - $start) * 1000;
            });

            echo "  Async operation: " . number_format($asyncTime, 3) . "ms\n";
            
            if ($asyncTime < 0.1) {
                echo "    ⚠️  WARNING: Async time suspiciously low - operations might not be executing\n";
            }
            
        } catch (Throwable $e) {
            echo "  ❌ Async operation failed: " . $e->getMessage() . "\n";
            echo "     Stack trace: " . $e->getTraceAsString() . "\n";
        }
        
        echo "\n";
    }

    private function testSequentialVsConcurrent()
    {
        echo "3️⃣ TESTING: Sequential vs Concurrent Behavior\n";
        echo str_repeat('-', 40) . "\n";

        $fileCount = 5;
        $content = str_repeat('Y', 500);

        // Sequential test
        echo "  Testing Sequential Execution:\n";
        try {
            $sequentialTime = Async::run(function () use ($fileCount, $content) {
                $start = microtime(true);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    $filename = $this->testDir . "/seq_{$i}.txt";
                    echo "    Processing file {$i}...\n";
                    
                    Async::await(Async::writeFile($filename, $content));
                    $readContent = Async::await(Async::readFile($filename));
                    Async::await(Async::deleteFile($filename));
                    
                    echo "    File {$i} completed (content length: " . strlen($readContent) . ")\n";
                }
                
                return (microtime(true) - $start) * 1000;
            });
            
            echo "    Sequential time: " . number_format($sequentialTime, 3) . "ms\n";
            
        } catch (Throwable $e) {
            echo "    ❌ Sequential test failed: " . $e->getMessage() . "\n";
        }

        // Concurrent test
        echo "  Testing Concurrent Execution:\n";
        try {
            $concurrentTime = Async::run(function () use ($fileCount, $content) {
                $start = microtime(true);
                
                $operations = [];
                for ($i = 0; $i < $fileCount; $i++) {
                    $operations[] = $this->createDebugFileOperation($i, $content);
                }
                
                echo "    Starting {$fileCount} concurrent operations...\n";
                Async::await(Async::concurrent($operations, $fileCount));
                echo "    All concurrent operations completed\n";
                
                return (microtime(true) - $start) * 1000;
            });
            
            echo "    Concurrent time: " . number_format($concurrentTime, 3) . "ms\n";
            
            if (isset($sequentialTime) && $concurrentTime < $sequentialTime) {
                $improvement = (($sequentialTime - $concurrentTime) / $sequentialTime) * 100;
                echo "    ✅ Concurrent is " . number_format($improvement, 1) . "% faster\n";
            }
            
        } catch (Throwable $e) {
            echo "    ❌ Concurrent test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    private function createDebugFileOperation(int $index, string $content): callable
    {
        return function () use ($index, $content) {
            $filename = $this->testDir . "/concurrent_{$index}.txt";
            $operationId = ++$this->operationCounter;
            
            $this->log($operationId, "START", "File {$index}");
            
            Async::await(Async::writeFile($filename, $content));
            $this->log($operationId, "WRITE", "File {$index}");
            
            $readContent = Async::await(Async::readFile($filename));
            $this->log($operationId, "READ", "File {$index} (length: " . strlen($readContent) . ")");
            
            Async::await(Async::deleteFile($filename));
            $this->log($operationId, "DELETE", "File {$index}");
            
            $this->log($operationId, "END", "File {$index}");
            
            return $operationId;
        };
    }

    private function testOperationOrdering()
    {
        echo "4️⃣ TESTING: Operation Ordering\n";
        echo str_repeat('-', 40) . "\n";

        $this->operationLog = [];
        $this->operationCounter = 0;

        try {
            Async::run(function () {
                $operations = [];
                for ($i = 0; $i < 3; $i++) {
                    $operations[] = $this->createDebugFileOperation($i, "test content {$i}");
                }
                Async::await(Async::concurrent($operations, 3));
            });

            echo "  Operation log (showing interleaving):\n";
            foreach ($this->operationLog as $entry) {
                echo "    [" . sprintf("%.3f", $entry['time']) . "ms] Op{$entry['id']}: {$entry['action']} - {$entry['details']}\n";
            }

            // Check for proper interleaving
            $this->analyzeOperationInterleaving();

        } catch (Throwable $e) {
            echo "  ❌ Operation ordering test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    private function testFileExistenceVerification()
    {
        echo "5️⃣ TESTING: File Existence Verification\n";
        echo str_repeat('-', 40) . "\n";

        try {
            $results = Async::run(function () {
                $filename = $this->testDir . '/existence_test.txt';
                $content = 'existence test content';
                
                // Test 1: File should not exist initially
                $existsBefore = Async::await(Async::fileExists($filename));
                echo "  File exists before create: " . ($existsBefore ? "YES" : "NO") . "\n";
                
                // Test 2: Create file and verify it exists
                Async::await(Async::writeFile($filename, $content));
                $existsAfterCreate = Async::await(Async::fileExists($filename));
                echo "  File exists after create: " . ($existsAfterCreate ? "YES" : "NO") . "\n";
                
                // Test 3: Verify file content
                if ($existsAfterCreate) {
                    $readContent = Async::await(Async::readFile($filename));
                    $contentMatch = $readContent === $content;
                    echo "  Content matches: " . ($contentMatch ? "YES" : "NO") . "\n";
                    if (!$contentMatch) {
                        echo "    Expected: '{$content}'\n";
                        echo "    Got: '{$readContent}'\n";
                    }
                }
                
                // Test 4: Delete and verify it's gone
                Async::await(Async::deleteFile($filename));
                $existsAfterDelete = Async::await(Async::fileExists($filename));
                echo "  File exists after delete: " . ($existsAfterDelete ? "YES" : "NO") . "\n";
                
                return [
                    'before' => $existsBefore,
                    'after_create' => $existsAfterCreate,
                    'after_delete' => $existsAfterDelete
                ];
            });

            // Verify expected behavior
            if (!$results['before'] && $results['after_create'] && !$results['after_delete']) {
                echo "  ✅ File operations working correctly\n";
            } else {
                echo "  ❌ File operations not working as expected\n";
            }

        } catch (Throwable $e) {
            echo "  ❌ File existence test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    private function testErrorHandling()
    {
        echo "6️⃣ TESTING: Error Handling\n";
        echo str_repeat('-', 40) . "\n";

        try {
            Async::run(function () {
                // Test 1: Read non-existent file
                try {
                    $content = Async::await(Async::readFile('/non/existent/file.txt'));
                    echo "  ❌ Reading non-existent file should fail but didn't\n";
                } catch (Throwable $e) {
                    echo "  ✅ Reading non-existent file properly throws: " . get_class($e) . "\n";
                }

                // Test 2: Write to invalid location
                try {
                    Async::await(Async::writeFile('/invalid/path/file.txt', 'content'));
                    echo "  ❌ Writing to invalid path should fail but didn't\n";
                } catch (Throwable $e) {
                    echo "  ✅ Writing to invalid path properly throws: " . get_class($e) . "\n";
                }

                // Test 3: Delete non-existent file
                try {
                    Async::await(Async::deleteFile('/non/existent/file.txt'));
                    echo "  ⚠️  Deleting non-existent file succeeded (might be OK)\n";
                } catch (Throwable $e) {
                    echo "  ✅ Deleting non-existent file throws: " . get_class($e) . "\n";
                }
            });

        } catch (Throwable $e) {
            echo "  ❌ Error handling test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    private function testFiberContext()
    {
        echo "7️⃣ TESTING: Fiber Context\n";
        echo str_repeat('-', 40) . "\n";

        try {
            Async::run(function () {
                echo "  Current fiber: " . (Fiber::getCurrent() ? "YES" : "NO") . "\n";
                
                $operations = [];
                for ($i = 0; $i < 3; $i++) {
                    $operations[] = function () use ($i) {
                        $fiber = Fiber::getCurrent();
                        echo "    Operation {$i} fiber: " . ($fiber ? spl_object_id($fiber) : "NONE") . "\n";
                        
                        $filename = $this->testDir . "/fiber_test_{$i}.txt";
                        Async::await(Async::writeFile($filename, "fiber test {$i}"));
                        
                        // Yield control to test fiber switching
                        Async::await(Async::delay(0.001));
                        
                        $content = Async::await(Async::readFile($filename));
                        Async::await(Async::deleteFile($filename));
                        
                        return "Operation {$i} completed in fiber " . ($fiber ? spl_object_id($fiber) : "NONE");
                    };
                }
                
                $results = Async::await(Async::concurrent($operations, 3));
                
                foreach ($results as $result) {
                    echo "  {$result}\n";
                }
            });

        } catch (Throwable $e) {
            echo "  ❌ Fiber context test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    private function testResourceCleanup()
    {
        echo "8️⃣ TESTING: Resource Cleanup\n";
        echo str_repeat('-', 40) . "\n";

        $initialFileCount = $this->countFilesInTestDir();
        echo "  Initial files in test dir: {$initialFileCount}\n";

        try {
            Async::run(function () {
                $operations = [];
                for ($i = 0; $i < 10; $i++) {
                    $operations[] = function () use ($i) {
                        $filename = $this->testDir . "/cleanup_test_{$i}.txt";
                        Async::await(Async::writeFile($filename, "cleanup test {$i}"));
                        
                        // Simulate some work
                        Async::await(Async::delay(0.001));
                        
                        $content = Async::await(Async::readFile($filename));
                        Async::await(Async::deleteFile($filename));
                        
                        return strlen($content);
                    };
                }
                
                $results = Async::await(Async::concurrent($operations, 5));
                echo "  Completed operations with results: " . implode(', ', $results) . "\n";
            });

            $finalFileCount = $this->countFilesInTestDir();
            echo "  Final files in test dir: {$finalFileCount}\n";

            if ($finalFileCount === $initialFileCount) {
                echo "  ✅ All temporary files cleaned up properly\n";
            } else {
                echo "  ⚠️  Files left behind: " . ($finalFileCount - $initialFileCount) . "\n";
                $this->listFilesInTestDir();
            }

        } catch (Throwable $e) {
            echo "  ❌ Resource cleanup test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    // Helper methods
    private function log(int $operationId, string $action, string $details)
    {
        $this->operationLog[] = [
            'id' => $operationId,
            'action' => $action,
            'details' => $details,
            'time' => microtime(true) * 1000
        ];
    }

    private function analyzeOperationInterleaving()
    {
        $operationStates = [];
        $interleaved = false;

        foreach ($this->operationLog as $entry) {
            $opId = $entry['id'];
            $action = $entry['action'];

            if ($action === 'START') {
                $operationStates[$opId] = 'running';
            } elseif ($action === 'END') {
                $operationStates[$opId] = 'completed';
            }

            // Check if multiple operations are running simultaneously
            $runningCount = 0;
            foreach ($operationStates as $state) {
                if ($state === 'running') {
                    $runningCount++;
                }
            }
            
            if ($runningCount > 1) {
                $interleaved = true;
            }
        }

        if ($interleaved) {
            echo "  ✅ Operations properly interleaved (true concurrency)\n";
        } else {
            echo "  ⚠️  Operations appear to run sequentially (possible issue)\n";
        }
    }

    private function countFilesInTestDir(): int
    {
        if (!is_dir($this->testDir)) return 0;
        return count(array_diff(scandir($this->testDir), ['.', '..']));
    }

    private function listFilesInTestDir()
    {
        if (!is_dir($this->testDir)) return;
        $files = array_diff(scandir($this->testDir), ['.', '..']);
        foreach ($files as $file) {
            echo "    - {$file}\n";
        }
    }

    public function __destruct()
    {
        if (is_dir($this->testDir)) {
            try {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    @$todo($fileinfo->getRealPath());
                }
                @rmdir($this->testDir);
            } catch (Exception $e) {
                // Suppress cleanup errors
            }
        }
    }
}

// Run the comprehensive debug
$debugger = new AsyncFileDebugger();
$debugger->runComprehensiveDebug();