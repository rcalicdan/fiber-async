<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

/**
 * Comprehensive benchmark comparing synchronous vs asynchronous file operations
 * 
 * This benchmark tests both single operations and concurrent scenarios to show
 * where async operations provide real performance benefits.
 */
class FileOperationsBenchmark
{
    private string $testDir;
    private array $results = [];
    private int $fileCount = 100;
    private string $sampleContent;

    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/file_benchmark_' . uniqid();
        $this->sampleContent = str_repeat("Sample content for benchmarking file operations.\n", 50);
    }

    public function run(): void
    {
        echo "🏁 File Operations Benchmark: Sync vs Async\n";
        echo "==========================================\n";
        echo "Test directory: {$this->testDir}\n";
        echo "File count: {$this->fileCount}\n";
        echo "Content size per file: " . strlen($this->sampleContent) . " bytes\n";
        echo str_repeat("=", 60) . "\n\n";

        try {
            $this->setupTestEnvironment();
            $this->runBenchmarks();
            $this->printResults();
        } catch (Exception $e) {
            echo "❌ Benchmark error: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
        }
    }

    private function setupTestEnvironment(): void
    {
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    private function runBenchmarks(): void
    {
        echo "🧪 Running Individual Operation Benchmarks\n";
        echo str_repeat("-", 40) . "\n";
        
        // Single operation benchmarks
        $this->benchmarkSingleFileWrite();
        $this->benchmarkSingleFileRead();
        $this->benchmarkSingleFileDelete();
        
        echo "\n🚀 Running Concurrent Operation Benchmarks\n";
        echo str_repeat("-", 40) . "\n";
        
        // Concurrent operation benchmarks (where async shines)
        $this->benchmarkConcurrentFileWrites();
        $this->benchmarkConcurrentFileReads();
        $this->benchmarkConcurrentFileOperations();
        $this->benchmarkMixedFileOperations();
        
        echo "\n💾 Running I/O Intensive Benchmarks\n";
        echo str_repeat("-", 40) . "\n";
        
        // I/O intensive scenarios
        $this->benchmarkLargeFileOperations();
        $this->benchmarkDirectoryOperations();
    }

    private function benchmarkSingleFileWrite(): void
    {
        echo "📝 Single File Write Operations...\n";
        
        // Sync write
        $syncTime = $this->measureTime(function () {
            $file = $this->testDir . '/sync_single_write.txt';
            file_put_contents($file, $this->sampleContent);
        });
        
        // Async write
        $asyncTime = $this->measureTime(function () {
            $file = $this->testDir . '/async_single_write.txt';
            Async::run(function () use ($file) {
                return Async::await(Async::writeFile($file, $this->sampleContent));
            });
        });
        
        $this->recordResult('Single File Write', $syncTime, $asyncTime);
    }

    private function benchmarkSingleFileRead(): void
    {
        echo "📖 Single File Read Operations...\n";
        
        // Prepare test file
        $testFile = $this->testDir . '/read_test.txt';
        file_put_contents($testFile, $this->sampleContent);
        
        // Sync read
        $syncTime = $this->measureTime(function () use ($testFile) {
            $content = file_get_contents($testFile);
        });
        
        // Async read
        $asyncTime = $this->measureTime(function () use ($testFile) {
            $content = Async::run(function () use ($testFile) {
                return Async::await(Async::readFile($testFile));
            });
        });
        
        $this->recordResult('Single File Read', $syncTime, $asyncTime);
    }

    private function benchmarkSingleFileDelete(): void
    {
        echo "🗑️ Single File Delete Operations...\n";
        
        // Sync delete
        $syncTestFile = $this->testDir . '/sync_delete_test.txt';
        file_put_contents($syncTestFile, $this->sampleContent);
        
        $syncTime = $this->measureTime(function () use ($syncTestFile) {
            unlink($syncTestFile);
        });
        
        // Async delete
        $asyncTestFile = $this->testDir . '/async_delete_test.txt';
        file_put_contents($asyncTestFile, $this->sampleContent);
        
        $asyncTime = $this->measureTime(function () use ($asyncTestFile) {
            Async::run(function () use ($asyncTestFile) {
                return Async::await(Async::deleteFile($asyncTestFile));
            });
        });
        
        $this->recordResult('Single File Delete', $syncTime, $asyncTime);
    }

    private function benchmarkConcurrentFileWrites(): void
    {
        echo "✍️ Concurrent File Write Operations ({$this->fileCount} files)...\n";
        
        // Sync concurrent writes (sequential)
        $syncTime = $this->measureTime(function () {
            for ($i = 0; $i < $this->fileCount; $i++) {
                $file = $this->testDir . "/sync_concurrent_{$i}.txt";
                file_put_contents($file, $this->sampleContent . " File #{$i}");
            }
        });
        
        // Async concurrent writes (truly concurrent)
        $asyncTime = $this->measureTime(function () {
            $operations = [];
            for ($i = 0; $i < $this->fileCount; $i++) {
                $operations[] = function () use ($i) {
                    $file = $this->testDir . "/async_concurrent_{$i}.txt";
                    return Async::await(Async::writeFile($file, $this->sampleContent . " File #{$i}"));
                };
            }
            
            Async::run(function () use ($operations) {
                return Async::await(Async::concurrent($operations, 10));
            });
        });
        
        $this->recordResult('Concurrent File Writes', $syncTime, $asyncTime);
    }

    private function benchmarkConcurrentFileReads(): void
    {
        echo "📚 Concurrent File Read Operations ({$this->fileCount} files)...\n";
        
        // Prepare files for reading
        for ($i = 0; $i < $this->fileCount; $i++) {
            file_put_contents($this->testDir . "/read_test_{$i}.txt", $this->sampleContent . " File #{$i}");
        }
        
        // Sync concurrent reads
        $syncTime = $this->measureTime(function () {
            $contents = [];
            for ($i = 0; $i < $this->fileCount; $i++) {
                $file = $this->testDir . "/read_test_{$i}.txt";
                $contents[] = file_get_contents($file);
            }
        });
        
        // Async concurrent reads
        $asyncTime = $this->measureTime(function () {
            $operations = [];
            for ($i = 0; $i < $this->fileCount; $i++) {
                $operations[] = function () use ($i) {
                    $file = $this->testDir . "/read_test_{$i}.txt";
                    return Async::await(Async::readFile($file));
                };
            }
            
            $contents = Async::run(function () use ($operations) {
                return Async::await(Async::concurrent($operations, 10));
            });
        });
        
        $this->recordResult('Concurrent File Reads', $syncTime, $asyncTime);
    }

    private function benchmarkConcurrentFileOperations(): void
    {
        echo "🔄 Mixed Concurrent Operations (write, read, copy, delete)...\n";
        
        $operationCount = 50;
        
        // Sync mixed operations
        $syncTime = $this->measureTime(function () use ($operationCount) {
            for ($i = 0; $i < $operationCount; $i++) {
                $file = $this->testDir . "/sync_mixed_{$i}.txt";
                $copyFile = $this->testDir . "/sync_mixed_copy_{$i}.txt";
                
                // Write
                file_put_contents($file, $this->sampleContent . " Mixed #{$i}");
                // Read
                $content = file_get_contents($file);
                // Copy
                copy($file, $copyFile);
                // Delete original
                unlink($file);
            }
        });
        
        // Async mixed operations
        $asyncTime = $this->measureTime(function () use ($operationCount) {
            $operations = [];
            
            for ($i = 0; $i < $operationCount; $i++) {
                $operations[] = function () use ($i) {
                    $file = $this->testDir . "/async_mixed_{$i}.txt";
                    $copyFile = $this->testDir . "/async_mixed_copy_{$i}.txt";
                    
                    // Write
                    Async::await(Async::writeFile($file, $this->sampleContent . " Mixed #{$i}"));
                    // Read
                    $content = Async::await(Async::readFile($file));
                    // Copy
                    Async::await(Async::copyFile($file, $copyFile));
                    // Delete original
                    return Async::await(Async::deleteFile($file));
                };
            }
            
            Async::run(function () use ($operations) {
                return Async::await(Async::concurrent($operations, 10));
            });
        });
        
        $this->recordResult('Mixed Concurrent Operations', $syncTime, $asyncTime);
    }

    private function benchmarkMixedFileOperations(): void
    {
        echo "🎭 Complex Mixed Operations Workflow...\n";
        
        $fileCount = 25;
        
        // Sync workflow
        $syncTime = $this->measureTime(function () use ($fileCount) {
            // Create directory
            $syncWorkDir = $this->testDir . '/sync_workflow';
            mkdir($syncWorkDir);
            
            // Write files
            for ($i = 0; $i < $fileCount; $i++) {
                file_put_contents("{$syncWorkDir}/file_{$i}.txt", "Content {$i}: " . $this->sampleContent);
            }
            
            // Read and process files
            $totalSize = 0;
            for ($i = 0; $i < $fileCount; $i++) {
                $content = file_get_contents("{$syncWorkDir}/file_{$i}.txt");
                $totalSize += strlen($content);
                
                // Create processed version
                file_put_contents("{$syncWorkDir}/processed_{$i}.txt", strtoupper($content));
            }
            
            // Clean up processed files
            for ($i = 0; $i < $fileCount; $i++) {
                unlink("{$syncWorkDir}/processed_{$i}.txt");
            }
        });
        
        // Async workflow
        $asyncTime = $this->measureTime(function () use ($fileCount) {
            Async::run(function () use ($fileCount) {
                // Create directory
                $asyncWorkDir = $this->testDir . '/async_workflow';
                Async::await(Async::createDirectory($asyncWorkDir));
                
                // Write files concurrently
                $writeOps = [];
                for ($i = 0; $i < $fileCount; $i++) {
                    $writeOps[] = function () use ($asyncWorkDir, $i) {
                        return Async::await(Async::writeFile("{$asyncWorkDir}/file_{$i}.txt", "Content {$i}: " . $this->sampleContent));
                    };
                }
                Async::await(Async::concurrent($writeOps, 10));
                
                // Read and process files concurrently
                $processOps = [];
                for ($i = 0; $i < $fileCount; $i++) {
                    $processOps[] = function () use ($asyncWorkDir, $i) {
                        $content = Async::await(Async::readFile("{$asyncWorkDir}/file_{$i}.txt"));
                        return Async::await(Async::writeFile("{$asyncWorkDir}/processed_{$i}.txt", strtoupper($content)));
                    };
                }
                Async::await(Async::concurrent($processOps, 10));
                
                // Clean up processed files concurrently
                $cleanupOps = [];
                for ($i = 0; $i < $fileCount; $i++) {
                    $cleanupOps[] = function () use ($asyncWorkDir, $i) {
                        return Async::await(Async::deleteFile("{$asyncWorkDir}/processed_{$i}.txt"));
                    };
                }
                
                return Async::await(Async::concurrent($cleanupOps, 10));
            });
        });
        
        $this->recordResult('Complex Mixed Workflow', $syncTime, $asyncTime);
    }

    private function benchmarkLargeFileOperations(): void
    {
        echo "📦 Large File Operations (1MB files)...\n";
        
        $largeContent = str_repeat($this->sampleContent, 100); // ~1MB
        $fileCount = 10;
        
        // Sync large file operations
        $syncTime = $this->measureTime(function () use ($largeContent, $fileCount) {
            for ($i = 0; $i < $fileCount; $i++) {
                $file = $this->testDir . "/sync_large_{$i}.txt";
                file_put_contents($file, $largeContent);
                $content = file_get_contents($file);
                unlink($file);
            }
        });
        
        // Async large file operations
        $asyncTime = $this->measureTime(function () use ($largeContent, $fileCount) {
            $operations = [];
            for ($i = 0; $i < $fileCount; $i++) {
                $operations[] = function () use ($largeContent, $i) {
                    $file = $this->testDir . "/async_large_{$i}.txt";
                    Async::await(Async::writeFile($file, $largeContent));
                    $content = Async::await(Async::readFile($file));
                    return Async::await(Async::deleteFile($file));
                };
            }
            
            Async::run(function () use ($operations) {
                return Async::await(Async::concurrent($operations, 5));
            });
        });
        
        $this->recordResult('Large File Operations', $syncTime, $asyncTime);
    }

    private function benchmarkDirectoryOperations(): void
    {
        echo "📁 Directory Operations...\n";
        
        $dirCount = 20;
        
        // Sync directory operations
        $syncTime = $this->measureTime(function () use ($dirCount) {
            $baseDir = $this->testDir . '/sync_dirs';
            
            // Create directories
            for ($i = 0; $i < $dirCount; $i++) {
                mkdir("{$baseDir}/dir_{$i}", 0755, true);
                file_put_contents("{$baseDir}/dir_{$i}/file.txt", "Directory {$i} content");
            }
            
            // Remove directories
            for ($i = 0; $i < $dirCount; $i++) {
                unlink("{$baseDir}/dir_{$i}/file.txt");
                rmdir("{$baseDir}/dir_{$i}");
            }
        });
        
        // Async directory operations
        $asyncTime = $this->measureTime(function () use ($dirCount) {
            Async::run(function () use ($dirCount) {
                $baseDir = $this->testDir . '/async_dirs';
                
                // Create directories concurrently
                $createOps = [];
                for ($i = 0; $i < $dirCount; $i++) {
                    $createOps[] = function () use ($baseDir, $i) {
                        Async::await(Async::createDirectory("{$baseDir}/dir_{$i}", ['recursive' => true]));
                        return Async::await(Async::writeFile("{$baseDir}/dir_{$i}/file.txt", "Directory {$i} content"));
                    };
                }
                Async::await(Async::concurrent($createOps, 10));
                
                // Remove directories concurrently
                $removeOps = [];
                for ($i = 0; $i < $dirCount; $i++) {
                    $removeOps[] = function () use ($baseDir, $i) {
                        Async::await(Async::deleteFile("{$baseDir}/dir_{$i}/file.txt"));
                        return Async::await(Async::removeDirectory("{$baseDir}/dir_{$i}"));
                    };
                }
                
                return Async::await(Async::concurrent($removeOps, 10));
            });
        });
        
        $this->recordResult('Directory Operations', $syncTime, $asyncTime);
    }

    private function measureTime(callable $operation): float
    {
        $memoryBefore = memory_get_usage(true);
        $start = hrtime(true);
        
        $operation();
        
        $end = hrtime(true);
        $memoryAfter = memory_get_usage(true);
        
        $timeMs = ($end - $start) / 1_000_000; // Convert nanoseconds to milliseconds
        
        return $timeMs;
    }

    private function recordResult(string $operation, float $syncTime, float $asyncTime): void
    {
        $improvement = (($syncTime - $asyncTime) / $syncTime) * 100;
        $speedRatio = $syncTime / $asyncTime;
        
        $this->results[$operation] = [
            'sync_time' => $syncTime,
            'async_time' => $asyncTime,
            'improvement' => $improvement,
            'speed_ratio' => $speedRatio
        ];
        
        $status = $improvement > 0 ? '🚀' : ($improvement < -10 ? '🐌' : '⚖️');
        $improvementText = $improvement > 0 
            ? sprintf("%.1f%% faster", $improvement)
            : sprintf("%.1f%% slower", abs($improvement));
        
        printf("  %-30s | Sync: %8.2fms | Async: %8.2fms | %s %s\n", 
            $operation, 
            $syncTime, 
            $asyncTime, 
            $status,
            $improvementText
        );
    }

    private function printResults(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 COMPREHENSIVE BENCHMARK RESULTS\n";
        echo str_repeat("=", 80) . "\n\n";
        
        echo "📈 Performance Summary:\n";
        echo sprintf("%-35s | %10s | %10s | %12s | %s\n", 
            "Operation", "Sync (ms)", "Async (ms)", "Improvement", "Speed Ratio");
        echo str_repeat("-", 80) . "\n";
        
        $totalSyncTime = 0;
        $totalAsyncTime = 0;
        $asyncWins = 0;
        $significantWins = 0;
        
        foreach ($this->results as $operation => $data) {
            $totalSyncTime += $data['sync_time'];
            $totalAsyncTime += $data['async_time'];
            
            if ($data['improvement'] > 0) {
                $asyncWins++;
            }
            if ($data['improvement'] > 20) {
                $significantWins++;
            }
            
            $improvementText = $data['improvement'] > 0 
                ? sprintf("+%.1f%%", $data['improvement'])
                : sprintf("%.1f%%", $data['improvement']);
            
            printf("%-35s | %8.2f | %8.2f | %10s | %6.2fx\n",
                $operation,
                $data['sync_time'],
                $data['async_time'],
                $improvementText,
                $data['speed_ratio']
            );
        }
        
        echo str_repeat("-", 80) . "\n";
        printf("%-35s | %8.2f | %8.2f | %10s | %6.2fx\n",
            "TOTAL",
            $totalSyncTime,
            $totalAsyncTime,
            sprintf("%+.1f%%", (($totalSyncTime - $totalAsyncTime) / $totalSyncTime) * 100),
            $totalSyncTime / $totalAsyncTime
        );
        
        echo "\n🏆 Analysis:\n";
        echo "• Async operations won: {$asyncWins}/" . count($this->results) . " tests\n";
        echo "• Significant wins (>20% faster): {$significantWins}/" . count($this->results) . " tests\n";
        echo "• Total time saved: " . sprintf("%.2fms (%.1f%% reduction)", 
            $totalSyncTime - $totalAsyncTime, 
            (($totalSyncTime - $totalAsyncTime) / $totalSyncTime) * 100) . "\n";
        
        echo "\n💡 Key Insights:\n";
        if ($asyncWins >= count($this->results) * 0.7) {
            echo "• ✅ Async operations show consistent performance benefits\n";
            echo "• 🚀 Best gains are in concurrent operations where async truly shines\n";
        } else {
            echo "• ⚖️ Mixed results - async overhead vs concurrent benefits\n";
            echo "• 📝 Single operations may show minimal or negative gains due to fiber overhead\n";
        }
        
        echo "• 🎯 Async is most beneficial for: I/O-bound concurrent operations\n";
        echo "• ⚠️ Async overhead is noticeable in: Simple single file operations\n";
        echo "• 🔄 Concurrency level impacts performance significantly\n";
    }

    private function cleanup(): void
    {
        echo "\n🧹 Cleaning up benchmark files...\n";
        
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

// Run the benchmark
echo "🏁 File Operations Benchmark Suite\n";
echo "===================================\n";
echo "System: " . PHP_OS . " | PHP: " . PHP_VERSION . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . " | Time Limit: " . ini_get('max_execution_time') . "s\n\n";

try {
    $benchmark = new FileOperationsBenchmark();
    $benchmark->run();
} catch (Throwable $e) {
    echo "💥 Benchmark crashed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}