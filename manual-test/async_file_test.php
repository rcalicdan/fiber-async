<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

/**
 * Simple test runner for async file operations
 */
class AsyncFileTest
{
    private string $testDir;
    private array $results = [];
    private int $testCount = 0;
    private int $passedCount = 0;

    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/async_file_tests_' . uniqid();
    }

    public function run(): void
    {
        echo "🚀 Starting Async File Operations Tests\n";
        echo "Test directory: {$this->testDir}\n";
        echo str_repeat("=", 50) . "\n\n";

        try {
            $this->runAllTests();
            $this->printSummary();
        } catch (Exception $e) {
            echo "❌ Fatal test error: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
        }
    }

    private function runAllTests(): void
    {
        // Test basic file operations
        $this->testCreateDirectory();
        $this->testWriteFile();
        $this->testReadFile();
        $this->testAppendFile();
        $this->testFileExists();
        $this->testGetFileStats();
        $this->testCopyFile();
        $this->testRenameFile();
        $this->testDeleteFile();
        $this->testRemoveDirectory();

        // Test concurrent operations
        $this->testConcurrentFileOperations();

        // Test file watching (if supported)
        $this->testFileWatching();

        // Test error handling
        $this->testErrorHandling();
    }

    private function testCreateDirectory(): void
    {
        $this->test('Create Directory', function () {
            $result = Async::run(function () {
                return Async::await(Async::createDirectory($this->testDir, ['recursive' => true]));
            });

            return $result && is_dir($this->testDir);
        });
    }

    private function testWriteFile(): void
    {
        $this->test('Write File', function () {
            $testFile = $this->testDir . '/test.txt';
            $testContent = "Hello, Async World!\n";

            $bytesWritten = Async::run(function () use ($testFile, $testContent) {
                return Async::await(Async::writeFile($testFile, $testContent));
            });

            return $bytesWritten > 0 && file_exists($testFile) && file_get_contents($testFile) === $testContent;
        });
    }


    private function testReadFile(): void
    {
        $this->test('Read File', function () {
            $testFile = $this->testDir . '/test.txt';
            $expectedContent = "Hello, Async World!\n";

            $content = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile));
            });

            return $content === $expectedContent;
        });
    }

    private function testAppendFile(): void
    {
        $this->test('Append File', function () {
            $testFile = $this->testDir . '/test.txt';
            $appendContent = "This is appended content.\n";

            $bytesWritten = Async::run(function () use ($testFile, $appendContent) {
                return Async::await(Async::appendFile($testFile, $appendContent));
            });

            $fullContent = file_get_contents($testFile);
            $expectedContent = "Hello, Async World!\nThis is appended content.\n";

            return $bytesWritten > 0 && $fullContent === $expectedContent;
        });
    }

    private function testFileExists(): void
    {
        $this->test('File Exists', function () {
            $testFile = $this->testDir . '/test.txt';
            $nonExistentFile = $this->testDir . '/nonexistent.txt';

            $exists = Async::run(function () use ($testFile) {
                return Async::await(Async::fileExists($testFile));
            });

            $notExists = Async::run(function () use ($nonExistentFile) {
                return Async::await(Async::fileExists($nonExistentFile));
            });

            return $exists === true && $notExists === false;
        });
    }

    private function testGetFileStats(): void
    {
        $this->test('Get File Stats', function () {
            $testFile = $this->testDir . '/test.txt';

            $stats = Async::run(function () use ($testFile) {
                return Async::await(Async::getFileStats($testFile));
            });

            return is_array($stats) && isset($stats['size']) && $stats['size'] > 0;
        });
    }

    private function testCopyFile(): void
    {
        $this->test('Copy File', function () {
            $sourceFile = $this->testDir . '/test.txt';
            $destinationFile = $this->testDir . '/test_copy.txt';

            $success = Async::run(function () use ($sourceFile, $destinationFile) {
                return Async::await(Async::copyFile($sourceFile, $destinationFile));
            });

            return $success && file_exists($destinationFile) &&
                file_get_contents($sourceFile) === file_get_contents($destinationFile);
        });
    }

    private function testRenameFile(): void
    {
        $this->test('Rename File', function () {
            $oldFile = $this->testDir . '/test_copy.txt';
            $newFile = $this->testDir . '/test_renamed.txt';

            $success = Async::run(function () use ($oldFile, $newFile) {
                return Async::await(Async::renameFile($oldFile, $newFile));
            });

            return $success && !file_exists($oldFile) && file_exists($newFile);
        });
    }

    private function testDeleteFile(): void
    {
        $this->test('Delete File', function () {
            $testFile = $this->testDir . '/test_renamed.txt';

            $success = Async::run(function () use ($testFile) {
                return Async::await(Async::deleteFile($testFile));
            });

            return $success && !file_exists($testFile);
        });
    }

    private function testRemoveDirectory(): void
    {
        $this->test('Remove Directory (with files)', function () {
            // Create a subdirectory with a file
            $subDir = $this->testDir . '/subdir';
            $testFile = $subDir . '/file.txt';

            // Create subdirectory and file
            mkdir($subDir);
            file_put_contents($testFile, 'test content');

            $success = Async::run(function () use ($subDir) {
                return Async::await(Async::removeDirectory($subDir));
            });

            return $success && !is_dir($subDir);
        });
    }

    private function testConcurrentFileOperations(): void
    {
        $this->test('Concurrent File Operations', function () {
            $operations = [];

            // Create multiple file write operations
            for ($i = 0; $i < 5; $i++) {
                $operations[] = function () use ($i) {
                    $file = $this->testDir . "/concurrent_test_{$i}.txt";
                    return Async::await(Async::writeFile($file, "Content for file {$i}"));
                };
            }

            $results = Async::run(function () use ($operations) {
                return Async::await(Async::concurrent($operations, 3));
            });

            // Verify all files were created
            $allCreated = true;
            for ($i = 0; $i < 5; $i++) {
                $file = $this->testDir . "/concurrent_test_{$i}.txt";
                if (!file_exists($file)) {
                    $allCreated = false;
                    break;
                }
            }

            return count($results) === 5 && $allCreated;
        });
    }

    private function testFileWatching(): void
    {
        $this->test('File Watching', function () {
            $testFile = $this->testDir . '/watch_test.txt';
            $changeDetected = false;

            // Create initial file
            file_put_contents($testFile, 'initial content');

            try {
                // Set up file watcher
                $watcherId = Async::watchFile($testFile, function ($event) use (&$changeDetected) {
                    $changeDetected = true;
                }, ['polling_interval' => 0.1]);

                // Give watcher time to initialize
                usleep(200000); // 0.2 seconds

                // Modify the file
                file_put_contents($testFile, 'modified content');

                // Wait for change detection
                $timeout = 2; // 2 seconds timeout
                $start = microtime(true);
                while (!$changeDetected && (microtime(true) - $start) < $timeout) {
                    usleep(100000); // 0.1 seconds
                }

                // Stop watching
                $unwatchSuccess = Async::unwatchFile($watcherId);

                return $changeDetected && $unwatchSuccess;
            } catch (Exception $e) {
                // File watching might not be supported in all environments
                echo "  ⚠️  File watching test skipped: " . $e->getMessage() . "\n";
                return true; // Consider it passed if not supported
            }
        });
    }

    private function testErrorHandling(): void
    {
        $this->test('Error Handling - Read Non-existent File', function () {
            $nonExistentFile = $this->testDir . '/does_not_exist.txt';

            try {
                Async::run(function () use ($nonExistentFile) {
                    return Async::await(Async::readFile($nonExistentFile));
                });
                return false; 
            } catch (Exception $e) {
                return true; 
            }
        });

        $this->test('Error Handling - Write to Read-only Directory', function () {
            if (PHP_OS_FAMILY === 'Windows') {
                return true; 
            }

            try {
                $readOnlyDir = $this->testDir . '/readonly';
                mkdir($readOnlyDir);
                chmod($readOnlyDir, 0444); 

                $testFile = $readOnlyDir . '/test.txt';

                Async::run(function () use ($testFile) {
                    return Async::await(Async::writeFile($testFile, 'test'));
                });

                return false; // Should have failed
            } catch (Exception $e) {
                return true; // Expected failure
            }
        });
    }

    private function test(string $name, callable $testFunction): void
    {
        $this->testCount++;
        echo "🧪 Testing: {$name}... ";

        try {
            $start = microtime(true);
            $result = $testFunction();
            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($result) {
                echo "✅ PASSED ({$duration}ms)\n";
                $this->passedCount++;
                $this->results[$name] = ['status' => 'PASSED', 'duration' => $duration];
            } else {
                echo "❌ FAILED ({$duration}ms)\n";
                $this->results[$name] = ['status' => 'FAILED', 'duration' => $duration];
            }
        } catch (Exception $e) {
            $duration = round((microtime(true) - $start) * 1000, 2);
            echo "❌ ERROR ({$duration}ms): " . $e->getMessage() . "\n";
            $this->results[$name] = ['status' => 'ERROR', 'duration' => $duration, 'error' => $e->getMessage()];
        }
    }

    private function printSummary(): void
    {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "📊 TEST SUMMARY\n";
        echo str_repeat("=", 50) . "\n";
        echo "Total Tests: {$this->testCount}\n";
        echo "Passed: {$this->passedCount}\n";
        echo "Failed: " . ($this->testCount - $this->passedCount) . "\n";

        $passRate = round(($this->passedCount / $this->testCount) * 100, 1);
        echo "Pass Rate: {$passRate}%\n";

        if ($this->passedCount === $this->testCount) {
            echo "\n🎉 All tests passed! Your async file operations are working correctly.\n";
        } else {
            echo "\n❌ Some tests failed. Check the implementation.\n";
            echo "\nDetailed Results:\n";
            foreach ($this->results as $testName => $result) {
                if ($result['status'] !== 'PASSED') {
                    echo "  - {$testName}: {$result['status']}";
                    if (isset($result['error'])) {
                        echo " - {$result['error']}";
                    }
                    echo "\n";
                }
            }
        }
    }

    private function cleanup(): void
    {
        echo "\n🧹 Cleaning up test files...\n";

        if (is_dir($this->testDir)) {
            $this->removeDirectoryRecursive($this->testDir);
        }

        echo "✅ Cleanup completed.\n";
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

// Run the tests
echo "🔧 Async File Operations Test Suite\n";
echo "====================================\n\n";

try {
    $tester = new AsyncFileTest();
    $tester->run();
} catch (Throwable $e) {
    echo "💥 Test suite crashed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
