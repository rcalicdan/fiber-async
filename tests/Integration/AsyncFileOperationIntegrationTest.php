<?php

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\FileHandler;

beforeEach(function () {
    resetEventLoop();
    
    // Create test directory
    $this->testDir = sys_get_temp_dir() . '/async_file_test_' . uniqid();
    mkdir($this->testDir, 0755, true);
    
    // Create some test files
    $this->testFile = $this->testDir . '/test.txt';
    $this->testContent = 'Hello, Async World!';
    file_put_contents($this->testFile, $this->testContent);
    
    $this->fileHandler = new FileHandler();
});

afterEach(function () {
    // Clean up test files
    if (is_dir($this->testDir)) {
        $files = glob($this->testDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->testDir);
    }
});

describe('FileHandler Basic Operations', function () {
    it('can read file asynchronously', function () {
        $result = Async::run(function () {
            return Async::await($this->fileHandler->read($this->testFile));
        });
        
        expect($result)->toBe($this->testContent);
    });
    
    it('can write file asynchronously', function () {
        $newFile = $this->testDir . '/new_file.txt';
        $content = 'New async content';
        
        $bytesWritten = Async::run(function () use ($newFile, $content) {
            return Async::await($this->fileHandler->write($newFile, $content));
        });
        
        expect($bytesWritten)->toBeGreaterThan(0);
        expect(file_get_contents($newFile))->toBe($content);
    });
    
    it('can check file existence asynchronously', function () {
        $exists = Async::run(function () {
            return Async::await($this->fileHandler->exists($this->testFile));
        });
        
        expect($exists)->toBe(true);
        
        $notExists = Async::run(function () {
            return Async::await($this->fileHandler->exists($this->testDir . '/nonexistent.txt'));
        });
        
        expect($notExists)->toBe(false);
    });
    
    it('can get file stats asynchronously', function () {
        $stats = Async::run(function () {
            return Async::await($this->fileHandler->stat($this->testFile));
        });
        
        expect($stats)->toBeArray();
        expect($stats['size'])->toBe(strlen($this->testContent));
    });
    
    it('can delete file asynchronously', function () {
        $fileToDelete = $this->testDir . '/to_delete.txt';
        file_put_contents($fileToDelete, 'Delete me');
        
        $result = Async::run(function () use ($fileToDelete) {
            return Async::await($this->fileHandler->unlink($fileToDelete));
        });
        
        expect($result)->toBe(true);
        expect(file_exists($fileToDelete))->toBe(false);
    });
    
    it('can create directory asynchronously', function () {
        $newDir = $this->testDir . '/new_directory';
        
        $result = Async::run(function () use ($newDir) {
            return Async::await($this->fileHandler->mkdir($newDir));
        });
        
        expect($result)->toBe(true);
        expect(is_dir($newDir))->toBe(true);
    });
    
    it('can list directory contents asynchronously', function () {
        // Create some files
        file_put_contents($this->testDir . '/file1.txt', 'content1');
        file_put_contents($this->testDir . '/file2.txt', 'content2');
        
        $contents = Async::run(function () {
            return Async::await($this->fileHandler->scandir($this->testDir));
        });
        
        expect($contents)->toBeArray();
        expect($contents)->toContain('test.txt', 'file1.txt', 'file2.txt');
    });
});

describe('FileHandler Concurrency Tests', function () {
    it('executes multiple file reads concurrently', function () {
        // Create multiple test files
        $files = [];
        for ($i = 1; $i <= 5; $i++) {
            $file = $this->testDir . "/concurrent_test_$i.txt";
            $content = "Content for file $i";
            file_put_contents($file, $content);
            $files[] = ['path' => $file, 'expected' => $content];
        }
        
        $startTime = microtime(true);
        
        $results = Async::run(function () use ($files) {
            $promises = [];
            foreach ($files as $file) {
                $promises[] = $this->fileHandler->read($file['path']);
            }
            
            return Async::await(Async::all($promises));
        });
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Verify all results are correct
        expect($results)->toHaveCount(5);
        foreach ($files as $index => $file) {
            expect($results[$index])->toBe($file['expected']);
        }
        
        // Concurrency should be faster than sequential execution
        // If truly concurrent, should be significantly less than 5x single operation time
        expect($executionTime)->toBeLessThan(2.0); // Generous timeout for concurrent execution
    });
    
    it('executes mixed file operations concurrently', function () {
        $operations = [];
        $expectedResults = [];
        
        // Mix of read, write, and existence checks
        for ($i = 1; $i <= 3; $i++) {
            // Read operation
            $readFile = $this->testDir . "/read_$i.txt";
            $readContent = "Read content $i";
            file_put_contents($readFile, $readContent);
            $operations[] = $this->fileHandler->read($readFile);
            $expectedResults[] = $readContent;
            
            // Write operation
            $writeFile = $this->testDir . "/write_$i.txt";
            $writeContent = "Write content $i";
            $operations[] = $this->fileHandler->write($writeFile, $writeContent);
            $expectedResults[] = strlen($writeContent); // bytes written
            
            // Existence check
            $operations[] = $this->fileHandler->exists($readFile);
            $expectedResults[] = true;
        }
        
        $startTime = microtime(true);
        
        $results = Async::run(function () use ($operations) {
            return Async::await(Async::all($operations));
        });
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        expect($results)->toHaveCount(9); // 3 operations × 3 iterations
        expect($executionTime)->toBeLessThan(3.0); // Should complete concurrently
        
        // Verify write operations actually wrote files
        for ($i = 1; $i <= 3; $i++) {
            $writeFile = $this->testDir . "/write_$i.txt";
            expect(file_exists($writeFile))->toBe(true);
            expect(file_get_contents($writeFile))->toBe("Write content $i");
        }
    });
    
    it('handles concurrent operations with controlled concurrency', function () {
        // Create 10 files for concurrent processing
        $files = [];
        for ($i = 1; $i <= 10; $i++) {
            $file = $this->testDir . "/batch_$i.txt";
            file_put_contents($file, "Batch content $i");
            $files[] = $file;
        }
        
        $tasks = array_map(function ($file) {
            return fn() => $this->fileHandler->read($file);
        }, $files);
        
        $startTime = microtime(true);
        
        $results = Async::runConcurrent($tasks, 3); // Limit to 3 concurrent operations
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        expect($results)->toHaveCount(10);
        foreach ($results as $index => $result) {
            expect($result)->toBe("Batch content " . ($index + 1));
        }
        
        // Should complete in batches, so faster than sequential but not as fast as unlimited concurrency
        expect($executionTime)->toBeLessThan(5.0);
    });
    
    it('handles large file operations concurrently', function () {
        // Create larger files to test with more realistic workload
        $largeContent = str_repeat('Large file content. ', 1000); // ~20KB content
        $files = [];
        
        for ($i = 1; $i <= 3; $i++) {
            $file = $this->testDir . "/large_$i.txt";
            file_put_contents($file, $largeContent . " File $i");
            $files[] = $file;
        }
        
        $startTime = microtime(true);
        
        $results = Async::run(function () use ($files) {
            $operations = array_map(function ($file) {
                return $this->fileHandler->read($file);
            }, $files);
            
            return Async::await(Async::all($operations));
        });
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        expect($results)->toHaveCount(3);
        foreach ($results as $index => $result) {
            expect($result)->toContain("File " . ($index + 1));
            expect(strlen($result))->toBeGreaterThan(20000);
        }
        
        expect($executionTime)->toBeLessThan(5.0);
    });
});

describe('FileHandler Error Handling', function () {
    it('handles file not found errors gracefully', function () {
        $this->expectException(Exception::class);
        
        Async::run(function () {
            return Async::await($this->fileHandler->read('/nonexistent/path/file.txt'));
        });
    });
    
    it('handles permission errors on write operations', function () {
        // Skip this test if running as root (which might have permission to write anywhere)
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Running as root - permission test not applicable');
        }
        
        $this->expectException(Exception::class);
        
        Async::run(function () {
            return Async::await($this->fileHandler->write('/root/forbidden.txt', 'content'));
        });
    });
    
    it('handles concurrent errors without affecting other operations', function () {
        $operations = [
            $this->fileHandler->read($this->testFile), // Should succeed
            $this->fileHandler->read('/nonexistent.txt'), // Should fail
            $this->fileHandler->exists($this->testFile), // Should succeed
        ];
        
        $results = [];
        $errors = [];
        
        Async::run(function () use ($operations, &$results, &$errors) {
            foreach ($operations as $index => $operation) {
                try {
                    $results[$index] = Async::await($operation);
                } catch (Exception $e) {
                    $errors[$index] = $e->getMessage();
                }
            }
        });
        
        expect($results)->toHaveKey(0); // First operation succeeded
        expect($results)->toHaveKey(2); // Third operation succeeded
        expect($errors)->toHaveKey(1);  // Second operation failed
        expect($results[0])->toBe($this->testContent);
        expect($results[2])->toBe(true);
    });
});

describe('FileHandler Performance Tests', function () {
    it('demonstrates performance improvement with concurrency', function () {
        // Create test files
        $files = [];
        for ($i = 1; $i <= 5; $i++) {
            $file = $this->testDir . "/perf_test_$i.txt";
            file_put_contents($file, "Performance test content $i");
            $files[] = $file;
        }
        
        // Test sequential execution
        $sequentialStart = microtime(true);
        $sequentialResults = [];
        foreach ($files as $file) {
            $sequentialResults[] = Async::run(function () use ($file) {
                return Async::await($this->fileHandler->read($file));
            });
        }
        $sequentialTime = microtime(true) - $sequentialStart;
        
        // Test concurrent execution
        $concurrentStart = microtime(true);
        $concurrentResults = Async::run(function () use ($files) {
            $operations = array_map(function ($file) {
                return $this->fileHandler->read($file);
            }, $files);
            
            return Async::await(Async::all($operations));
        });
        $concurrentTime = microtime(true) - $concurrentStart;
        
        // Verify results are the same
        expect($concurrentResults)->toEqual($sequentialResults);
        
        // Concurrent should be faster (allow some margin for test environment variability)
        expect($concurrentTime)->toBeLessThan($sequentialTime * 0.8);
        
        echo "\nPerformance comparison:";
        echo "\nSequential time: " . round($sequentialTime, 4) . "s";
        echo "\nConcurrent time: " . round($concurrentTime, 4) . "s";
        echo "\nImprovement: " . round(($sequentialTime - $concurrentTime) / $sequentialTime * 100, 1) . "%\n";
    });
});

describe('FileHandler Integration with Async Facade', function () {
    it('integrates properly with Async facade methods', function () {
        $file1 = $this->testDir . '/integration1.txt';
        $file2 = $this->testDir . '/integration2.txt';
        
        file_put_contents($file1, 'Integration test 1');
        file_put_contents($file2, 'Integration test 2');
        
        $result = Async::run(function () use ($file1, $file2) {
            // Test with Async::all
            $readPromises = [
                Async::readFile($file1),
                Async::readFile($file2)
            ];
            
            $readResults = Async::await(Async::all($readPromises));
            
            // Test with delay to simulate real async scenario
            Async::await(Async::delay(0.1));
            
            return $readResults;
        });
        
        expect($result)->toHaveCount(2);
        expect($result[0])->toBe('Integration test 1');
        expect($result[1])->toBe('Integration test 2');
    });
    
    it('works with timeout constraints', function () {
        $file = $this->testDir . '/timeout_test.txt';
        file_put_contents($file, 'Timeout test content');
        
        $result = Async::runWithTimeout(function () use ($file) {
            return Async::readFile($file);
        }, 5.0); // 5 second timeout
        
        expect($result)->toBe('Timeout test content');
    });
});