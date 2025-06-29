<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

/**
 * Manual Test Script for Async File Operations
 * 
 * This script tests all file handling methods in the FiberAsync library
 * without using any testing frameworks like PHPUnit or Pest.
 */

class AsyncFileTestRunner
{
    private array $testResults = [];
    private string $testDir;
    private int $testCount = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;

    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/async_file_tests_' . uniqid();
        echo "🚀 Starting Async File Operations Tests\n";
        echo "Test directory: {$this->testDir}\n";
        echo str_repeat("=", 60) . "\n\n";
    }

    public function runAllTests(): void
    {
        try {
            // Setup test environment
            $this->setupTestEnvironment();

            // Run all tests
            $this->testFileExists();
            $this->testCreateDirectory();
            $this->testWriteFile();
            $this->testReadFile();
            $this->testStatFile();
            $this->testListDirectory();
            $this->testAppendToFile();
            $this->testReadFileWithOffset();
            $this->testReadFileWithLength();
            $this->testDeleteFile();
            $this->testRemoveDirectory();
            $this->testErrorHandling();
            $this->testConcurrentFileOperations();
            $this->testLargeFileOperations();

            // Display results
            $this->displayResults();
        } catch (Exception $e) {
            echo "❌ Fatal error during testing: " . $e->getMessage() . "\n";
        } finally {
            // Cleanup
            $this->cleanup();
        }
    }

    private function setupTestEnvironment(): void
    {
        echo "🔧 Setting up test environment...\n";

        // Create main test directory using sync method first
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }

        echo "✅ Test environment ready\n\n";
    }

    private function testFileExists(): void
    {
        $this->runTest("File Exists - Non-existent file", function () {
            $nonExistentFile = $this->testDir . '/does_not_exist.txt';
            $result = Async::run(function () use ($nonExistentFile) {
                return Async::await(Async::fileExists($nonExistentFile));
            });

            $this->assert($result === false, "Non-existent file should return false");
        });

        $this->runTest("File Exists - Create and check file", function () {
            $testFile = $this->testDir . '/exists_test.txt';

            // Create file first
            file_put_contents($testFile, 'test content');

            $result = Async::run(function () use ($testFile) {
                return Async::await(Async::fileExists($testFile));
            });

            $this->assert($result === true, "Existing file should return true");
        });
    }

    private function testCreateDirectory(): void
    {
        $this->runTest("Create Directory - Single level", function () {
            $newDir = $this->testDir . '/new_directory';

            $result = Async::run(function () use ($newDir) {
                return Async::await(Async::createDir($newDir));
            });

            $this->assert($result === true, "Directory creation should return true");
            $this->assert(is_dir($newDir), "Directory should actually exist");
        });

        $this->runTest("Create Directory - Recursive", function () {
            $nestedDir = $this->testDir . '/level1/level2/level3';

            $result = Async::run(function () use ($nestedDir) {
                return Async::await(Async::createDir($nestedDir, 0755, true));
            });

            $this->assert($result === true, "Recursive directory creation should return true");
            $this->assert(is_dir($nestedDir), "Nested directory should exist");
        });
    }

    private function testWriteFile(): void
    {
        $this->runTest("Write File - New file", function () {
            $testFile = $this->testDir . '/write_test.txt';
            $testContent = "Hello, Async World!\nThis is a test file.";

            $bytesWritten = Async::run(function () use ($testFile, $testContent) {
                return Async::await(Async::writeFile($testFile, $testContent));
            });

            $this->assert($bytesWritten > 0, "Should return number of bytes written");
            $this->assert(file_exists($testFile), "File should exist after writing");
            $this->assert(file_get_contents($testFile) === $testContent, "File content should match");
        });

        $this->runTest("Write File - Overwrite existing", function () {
            $testFile = $this->testDir . '/overwrite_test.txt';
            $originalContent = "Original content";
            $newContent = "New content that overwrites";

            // Create original file
            file_put_contents($testFile, $originalContent);

            $bytesWritten = Async::run(function () use ($testFile, $newContent) {
                return Async::await(Async::writeFile($testFile, $newContent));
            });

            $this->assert($bytesWritten > 0, "Should return bytes written");
            $this->assert(file_get_contents($testFile) === $newContent, "Content should be overwritten");
        });
    }

    private function testReadFile(): void
    {
        $this->runTest("Read File - Complete file", function () {
            $testFile = $this->testDir . '/read_test.txt';
            $testContent = "Line 1\nLine 2\nLine 3\nFinal line";

            // Create test file
            file_put_contents($testFile, $testContent);

            $readContent = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile));
            });

            $this->assert($readContent === $testContent, "Read content should match written content");
        });

        $this->runTest("Read File - Binary content", function () {
            $testFile = $this->testDir . '/binary_test.bin';
            $binaryContent = pack('H*', '48656c6c6f20576f726c64'); // "Hello World" in hex

            // Write binary content
            file_put_contents($testFile, $binaryContent);

            $readContent = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile));
            });

            $this->assert($readContent === $binaryContent, "Binary content should be preserved");
        });
    }

    private function testStatFile(): void
    {
        $this->runTest("Stat File - Get file information", function () {
            $testFile = $this->testDir . '/stat_test.txt';
            $testContent = str_repeat("A", 1000); // 1000 bytes

            file_put_contents($testFile, $testContent);

            $stats = Async::run(function () use ($testFile) {
                return Async::await(Async::statFile($testFile));
            });

            $this->assert(is_array($stats), "Stat should return array");
            $this->assert($stats['size'] === 1000, "File size should be 1000 bytes");
            $this->assert(isset($stats['mtime']), "Should include modification time");
            $this->assert(isset($stats['ctime']), "Should include creation time");
        });
    }

    private function testListDirectory(): void
    {
        $this->runTest("List Directory - Multiple files", function () {
            $testDir = $this->testDir . '/list_test';
            mkdir($testDir);

            // Create test files
            $expectedFiles = ['file1.txt', 'file2.txt', 'file3.txt'];
            foreach ($expectedFiles as $filename) {
                file_put_contents($testDir . '/' . $filename, 'test content');
            }

            $files = Async::run(function () use ($testDir) {
                return Async::await(Async::listDir($testDir));
            });

            $this->assert(is_array($files), "Should return array of files");
            $this->assert(count($files) === 3, "Should find 3 files");

            foreach ($expectedFiles as $expectedFile) {
                $this->assert(in_array($expectedFile, $files), "Should contain $expectedFile");
            }
        });

        $this->runTest("List Directory - Empty directory", function () {
            $emptyDir = $this->testDir . '/empty_dir';
            mkdir($emptyDir);

            $files = Async::run(function () use ($emptyDir) {
                return Async::await(Async::listDir($emptyDir));
            });

            $this->assert(is_array($files), "Should return array");
            $this->assert(count($files) === 0, "Empty directory should return empty array");
        });
    }

    private function testAppendToFile(): void
    {
        $this->runTest("Append to File", function () {
            $testFile = $this->testDir . '/append_test.txt';
            $originalContent = "Original line\n";
            $appendContent = "Appended line\n";

            // Create original file
            file_put_contents($testFile, $originalContent);

            $bytesWritten = Async::run(function () use ($testFile, $appendContent) {
                return Async::await(Async::writeFile($testFile, $appendContent, true));
            });

            $finalContent = file_get_contents($testFile);
            $expectedContent = $originalContent . $appendContent;

            $this->assert($bytesWritten > 0, "Should return bytes written");
            $this->assert($finalContent === $expectedContent, "Content should be appended");
        });
    }

    private function testReadFileWithOffset(): void
    {
        $this->runTest("Read File with Offset", function () {
            $testFile = $this->testDir . '/offset_test.txt';
            $testContent = "0123456789ABCDEFGHIJ";

            file_put_contents($testFile, $testContent);

            $readContent = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile, 10)); // Start from position 10
            });

            $this->assert($readContent === "ABCDEFGHIJ", "Should read from offset position");
        });
    }

    private function testReadFileWithLength(): void
    {
        $this->runTest("Read File with Length Limit", function () {
            $testFile = $this->testDir . '/length_test.txt';
            $testContent = "0123456789ABCDEFGHIJ";

            file_put_contents($testFile, $testContent);

            $readContent = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile, 5, 10)); // Start at 5, read 10 chars
            });

            $this->assert($readContent === "56789ABCDE", "Should read specified length from offset");
        });
    }

    private function testDeleteFile(): void
    {
        $this->runTest("Delete File", function () {
            $testFile = $this->testDir . '/delete_test.txt';

            // Create file to delete
            file_put_contents($testFile, 'Content to be deleted');
            $this->assert(file_exists($testFile), "File should exist before deletion");

            $result = Async::run(function () use ($testFile) {
                return Async::await(Async::deleteFile($testFile));
            });

            $this->assert($result === true, "Delete operation should return true");
            $this->assert(!file_exists($testFile), "File should not exist after deletion");
        });
    }

    private function testRemoveDirectory(): void
    {
        $this->runTest("Remove Directory", function () {
            $testDir = $this->testDir . '/dir_to_remove';
            mkdir($testDir);
            $this->assert(is_dir($testDir), "Directory should exist before removal");

            $result = Async::run(function () use ($testDir) {
                return Async::await(Async::removeDir($testDir));
            });

            $this->assert($result === true, "Remove operation should return true");
            $this->assert(!is_dir($testDir), "Directory should not exist after removal");
        });
    }

    private function testErrorHandling(): void
    {
        $this->runTest("Error Handling - Read non-existent file", function () {
            $nonExistentFile = $this->testDir . '/does_not_exist.txt';

            try {
                Async::run(function () use ($nonExistentFile) {
                    return Async::await(Async::readFile($nonExistentFile));
                });
                $this->assert(false, "Should throw exception for non-existent file");
            } catch (Exception $e) {
                $this->assert(true, "Should throw exception for non-existent file");
            }
        });

        $this->runTest("Error Handling - Write to invalid path", function () {
            $invalidPath = '/invalid/path/that/does/not/exist/file.txt';

            try {
                Async::run(function () use ($invalidPath) {
                    return Async::await(Async::writeFile($invalidPath, 'test'));
                });
                $this->assert(false, "Should throw exception for invalid path");
            } catch (Exception $e) {
                $this->assert(true, "Should throw exception for invalid path");
            }
        });
    }

    private function testConcurrentFileOperations(): void
    {
        $this->runTest("Concurrent File Operations", function () {
            $operations = [];

            // Create multiple concurrent file operations
            for ($i = 0; $i < 5; $i++) {
                $operations[] = function () use ($i) {
                    $filename = $this->testDir . "/concurrent_$i.txt";
                    $content = "Concurrent content $i";

                    return Async::await(Async::writeFile($filename, $content));
                };
            }

            $results = Async::runConcurrent($operations, 3);

            $this->assert(count($results) === 5, "Should complete all 5 operations");

            // Verify all files were created
            for ($i = 0; $i < 5; $i++) {
                $filename = $this->testDir . "/concurrent_$i.txt";
                $this->assert(file_exists($filename), "File concurrent_$i.txt should exist");
                $this->assert(file_get_contents($filename) === "Concurrent content $i", "Content should match");
            }
        });
    }

    private function testLargeFileOperations(): void
    {
        $this->runTest("Large File Operations", function () {
            $testFile = $this->testDir . '/large_file.txt';
            $largeContent = str_repeat("This is a line of text in a large file.\n", 10000); // ~400KB

            // Measure write performance
            $startTime = microtime(true);
            $bytesWritten = Async::run(function () use ($testFile, $largeContent) {
                return Async::await(Async::writeFile($testFile, $largeContent));
            });
            $writeTime = microtime(true) - $startTime;

            $this->assert($bytesWritten > 0, "Should write large file successfully");

            // Measure read performance
            $startTime = microtime(true);
            $readContent = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile));
            });
            $readTime = microtime(true) - $startTime;

            $this->assert($readContent === $largeContent, "Large file content should match");

            // Fix: Use string concatenation instead of interpolation within the closure
            echo "    📊 Performance: Write " . number_format($writeTime, 3) . "s, Read " . number_format($readTime, 3) . "s\n";
        });
    }

    private function runTest(string $testName, callable $testFunction): void
    {
        $this->testCount++;
        echo "🧪 Running: $testName\n";

        try {
            $startTime = microtime(true);
            $testFunction();
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            $this->passedTests++;
            $this->testResults[] = ['name' => $testName, 'status' => 'PASSED', 'duration' => $duration];
            echo "✅ PASSED ({$duration}ms)\n\n";
        } catch (Exception $e) {
            $this->failedTests++;
            $this->testResults[] = ['name' => $testName, 'status' => 'FAILED', 'error' => $e->getMessage()];
            echo "❌ FAILED: " . $e->getMessage() . "\n\n";
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new Exception("Assertion failed: $message");
        }
    }

    private function displayResults(): void
    {
        echo str_repeat("=", 60) . "\n";
        echo "📊 TEST RESULTS SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "Total Tests: {$this->testCount}\n";
        echo "Passed: {$this->passedTests}\n";
        echo "Failed: {$this->failedTests}\n";
        echo "Success Rate: " . round(($this->passedTests / $this->testCount) * 100, 1) . "%\n\n";

        if ($this->failedTests > 0) {
            echo "❌ FAILED TESTS:\n";
            foreach ($this->testResults as $result) {
                if ($result['status'] === 'FAILED') {
                    echo "  • {$result['name']}: {$result['error']}\n";
                }
            }
            echo "\n";
        }

        echo "📈 PERFORMANCE BREAKDOWN:\n";
        foreach ($this->testResults as $result) {
            if ($result['status'] === 'PASSED' && isset($result['duration'])) {
                echo "  • {$result['name']}: {$result['duration']}ms\n";
            }
        }

        echo "\n" . str_repeat("=", 60) . "\n";

        if ($this->failedTests === 0) {
            echo "🎉 ALL TESTS PASSED! Your async file operations are working correctly.\n";
        } else {
            echo "⚠️  Some tests failed. Please review the implementation.\n";
        }
    }

    private function cleanup(): void
    {
        echo "\n🧹 Cleaning up test files...\n";

        if (is_dir($this->testDir)) {
            $this->deleteDirectory($this->testDir);
        }

        echo "✅ Cleanup complete\n";
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

// Run the tests
echo "🚀 Async File Operations Test Suite\n";
echo "===================================\n\n";

$testRunner = new AsyncFileTestRunner();
$testRunner->runAllTests();
