<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

class RealisticAsyncBenchmark
{
    private string $testDir;
    private array $testFiles = [];
    
    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/realistic_async_benchmark_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
        echo "Test directory: {$this->testDir}\n\n";
    }

    public function runAllBenchmarks()
    {
        echo "🚀 REALISTIC ASYNC FILE I/O BENCHMARK\n";
        echo str_repeat('=', 70) . "\n\n";

        // Test 1: Different file sizes
        $this->benchmarkFileSizes();
        
        // Test 2: High concurrency with realistic file operations
        $this->benchmarkHighConcurrency();
        
        // Test 3: Mixed read/write operations
        $this->benchmarkMixedOperations();
        
        // Test 4: Large file processing
        $this->benchmarkLargeFiles();
        
        // Test 5: Directory operations
        $this->benchmarkDirectoryOperations();
        
        // Test 6: Real-world simulation (log processing)
        $this->benchmarkLogProcessing();
        
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "🏁 BENCHMARK COMPLETE\n";
    }

    private function benchmarkFileSizes()
    {
        echo "📊 Test 1: File Size Impact\n";
        echo str_repeat('-', 50) . "\n";
        
        $sizes = [
            '1KB' => 1024,
            '10KB' => 10240,
            '100KB' => 102400,
            '1MB' => 1048576,
            '5MB' => 5242880
        ];
        
        foreach ($sizes as $label => $size) {
            $this->benchmarkFileSize($label, $size);
        }
        echo "\n";
    }

    private function benchmarkFileSize(string $label, int $size)
    {
        $fileCount = $size > 1048576 ? 5 : 20; // Fewer files for larger sizes
        $content = str_repeat('A', $size);
        
        // Sync benchmark
        $syncTime = $this->measureTime(function() use ($fileCount, $content) {
            for ($i = 0; $i < $fileCount; $i++) {
                $filename = $this->testDir . "/sync_size_{$i}.txt";
                file_put_contents($filename, $content);
                $data = file_get_contents($filename);
                unlink($filename);
            }
        });
        
        // Async benchmark
        $asyncTime = $this->measureTime(function() use ($fileCount, $content) {
            Async::run(function() use ($fileCount, $content) {
                $operations = [];
                for ($i = 0; $i < $fileCount; $i++) {
                    $operations[] = function() use ($i, $content) {
                        $filename = $this->testDir . "/async_size_{$i}.txt";
                        Async::await(Async::writeFile($filename, $content));
                        $data = Async::await(Async::readFile($filename));
                        Async::await(Async::deleteFile($filename));
                        return strlen($data);
                    };
                }
                Async::await(Async::concurrent($operations, min($fileCount, 10)));
            });
        });
        
        $this->printComparison($label, $syncTime, $asyncTime);
    }

    private function benchmarkHighConcurrency()
    {
        echo "⚡ Test 2: High Concurrency (100 small files)\n";
        echo str_repeat('-', 50) . "\n";
        
        $fileCount = 100;
        $content = str_repeat('B', 1024); // 1KB files
        
        // Sync benchmark
        $syncTime = $this->measureTime(function() use ($fileCount, $content) {
            for ($i = 0; $i < $fileCount; $i++) {
                $filename = $this->testDir . "/sync_concurrent_{$i}.txt";
                file_put_contents($filename, $content);
                $data = file_get_contents($filename);
                unlink($filename);
            }
        });
        
        // Async benchmark with different concurrency levels
        $concurrencyLevels = [5, 10, 20, 50];
        
        foreach ($concurrencyLevels as $concurrency) {
            $asyncTime = $this->measureTime(function() use ($fileCount, $content, $concurrency) {
                Async::run(function() use ($fileCount, $content, $concurrency) {
                    $operations = [];
                    for ($i = 0; $i < $fileCount; $i++) {
                        $operations[] = function() use ($i, $content) {
                            $filename = $this->testDir . "/async_concurrent_{$i}.txt";
                            Async::await(Async::writeFile($filename, $content));
                            $data = Async::await(Async::readFile($filename));
                            Async::await(Async::deleteFile($filename));
                            return strlen($data);
                        };
                    }
                    Async::await(Async::concurrent($operations, $concurrency));
                });
            });
            
            $this->printComparison("Concurrency {$concurrency}", $syncTime, $asyncTime);
        }
        echo "\n";
    }

    private function benchmarkMixedOperations()
    {
        echo "🔀 Test 3: Mixed Operations (Read/Write/Copy/Delete)\n";
        echo str_repeat('-', 50) . "\n";
        
        // Create base files for testing
        $baseFiles = [];
        for ($i = 0; $i < 20; $i++) {
            $filename = $this->testDir . "/base_{$i}.txt";
            $content = str_repeat('C', 5000 + ($i * 100)); // Variable size
            file_put_contents($filename, $content);
            $baseFiles[] = $filename;
        }
        
        // Sync benchmark
        $syncTime = $this->measureTime(function() use ($baseFiles) {
            foreach ($baseFiles as $i => $baseFile) {
                $operation = $i % 4;
                switch ($operation) {
                    case 0: // Read
                        $data = file_get_contents($baseFile);
                        break;
                    case 1: // Write
                        $newFile = $this->testDir . "/sync_new_{$i}.txt";
                        file_put_contents($newFile, "New content {$i}");
                        break;
                    case 2: // Copy
                        $copyFile = $this->testDir . "/sync_copy_{$i}.txt";
                        copy($baseFile, $copyFile);
                        break;
                    case 3: // Delete temp files
                        $tempFile = $this->testDir . "/sync_new_" . ($i-3) . ".txt";
                        if (file_exists($tempFile)) unlink($tempFile);
                        break;
                }
            }
        });
        
        // Async benchmark
        $asyncTime = $this->measureTime(function() use ($baseFiles) {
            Async::run(function() use ($baseFiles) {
                $operations = [];
                foreach ($baseFiles as $i => $baseFile) {
                    $operations[] = function() use ($i, $baseFile) {
                        $operation = $i % 4;
                        switch ($operation) {
                            case 0: // Read
                                return Async::await(Async::readFile($baseFile));
                            case 1: // Write
                                $newFile = $this->testDir . "/async_new_{$i}.txt";
                                Async::await(Async::writeFile($newFile, "New content {$i}"));
                                return "written";
                            case 2: // Copy
                                $copyFile = $this->testDir . "/async_copy_{$i}.txt";
                                Async::await(Async::copyFile($baseFile, $copyFile));
                                return "copied";
                            case 3: // Delete temp files
                                $tempFile = $this->testDir . "/async_new_" . ($i-3) . ".txt";
                                if (Async::await(Async::fileExists($tempFile))) {
                                    Async::await(Async::deleteFile($tempFile));
                                }
                                return "deleted";
                        }
                    };
                }
                Async::await(Async::concurrent($operations, 8));
            });
        });
        
        $this->printComparison("Mixed Operations", $syncTime, $asyncTime);
        
        // Clean up
        foreach ($baseFiles as $file) {
            if (file_exists($file)) unlink($file);
        }
        echo "\n";
    }

    private function benchmarkLargeFiles()
    {
        echo "📦 Test 4: Large File Processing\n";
        echo str_repeat('-', 50) . "\n";
        
        $largeContent = str_repeat('Large file content. ', 50000); // ~1MB
        $fileCount = 5;
        
        // Sync benchmark
        $syncTime = $this->measureTime(function() use ($largeContent, $fileCount) {
            for ($i = 0; $i < $fileCount; $i++) {
                $filename = $this->testDir . "/sync_large_{$i}.txt";
                file_put_contents($filename, $largeContent);
                $data = file_get_contents($filename);
                // Simulate processing
                $lines = explode('.', $data);
                $processedContent = implode('.', array_map('trim', $lines));
                file_put_contents($filename . '.processed', $processedContent);
                unlink($filename);
                unlink($filename . '.processed');
            }
        });
        
        // Async benchmark
        $asyncTime = $this->measureTime(function() use ($largeContent, $fileCount) {
            Async::run(function() use ($largeContent, $fileCount) {
                $operations = [];
                for ($i = 0; $i < $fileCount; $i++) {
                    $operations[] = function() use ($i, $largeContent) {
                        $filename = $this->testDir . "/async_large_{$i}.txt";
                        Async::await(Async::writeFile($filename, $largeContent));
                        $data = Async::await(Async::readFile($filename));
                        // Simulate processing
                        $lines = explode('.', $data);
                        $processedContent = implode('.', array_map('trim', $lines));
                        Async::await(Async::writeFile($filename . '.processed', $processedContent));
                        Async::await(Async::deleteFile($filename));
                        Async::await(Async::deleteFile($filename . '.processed'));
                        return strlen($data);
                    };
                }
                Async::await(Async::concurrent($operations, 3)); // Lower concurrency for large files
            });
        });
        
        $this->printComparison("Large Files", $syncTime, $asyncTime);
        echo "\n";
    }

    private function benchmarkDirectoryOperations()
    {
        echo "📁 Test 5: Directory Operations\n";
        echo str_repeat('-', 50) . "\n";
        
        // Create test directory structure
        $dirs = [];
        for ($i = 0; $i < 10; $i++) {
            $dirs[] = $this->testDir . "/subdir_{$i}";
        }
        
        // Sync benchmark
        $syncTime = $this->measureTime(function() use ($dirs) {
            foreach ($dirs as $i => $dir) {
                mkdir($dir, 0777, true);
                for ($j = 0; $j < 5; $j++) {
                    $filename = $dir . "/file_{$j}.txt";
                    file_put_contents($filename, "Content for file {$j} in dir {$i}");
                }
                // List directory contents
                $files = scandir($dir);
                // Clean up
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        unlink($dir . '/' . $file);
                    }
                }
                rmdir($dir);
            }
        });
        
        // Async benchmark
        $asyncTime = $this->measureTime(function() use ($dirs) {
            Async::run(function() use ($dirs) {
                $operations = [];
                foreach ($dirs as $i => $dir) {
                    $operations[] = function() use ($i, $dir) {
                        mkdir($dir, 0777, true);
                        $fileOps = [];
                        for ($j = 0; $j < 5; $j++) {
                            $fileOps[] = function() use ($i, $j, $dir) {
                                $filename = $dir . "/file_{$j}.txt";
                                Async::await(Async::writeFile($filename, "Content for file {$j} in dir {$i}"));
                                return $filename;
                            };
                        }
                        $files = Async::await(Async::concurrent($fileOps, 5));
                        
                        // Clean up files
                        foreach ($files as $file) {
                            Async::await(Async::deleteFile($file));
                        }
                        rmdir($dir);
                        return count($files);
                    };
                }
                Async::await(Async::concurrent($operations, 5));
            });
        });
        
        $this->printComparison("Directory Operations", $syncTime, $asyncTime);
        echo "\n";
    }

    private function benchmarkLogProcessing()
    {
        echo "📝 Test 6: Log Processing Simulation\n";
        echo str_repeat('-', 50) . "\n";
        
        // Create sample log files
        $logFiles = [];
        for ($i = 0; $i < 10; $i++) {
            $filename = $this->testDir . "/log_{$i}.txt";
            $logContent = $this->generateLogContent(1000); // 1000 log entries
            file_put_contents($filename, $logContent);
            $logFiles[] = $filename;
        }
        
        // Sync benchmark
        $syncTime = $this->measureTime(function() use ($logFiles) {
            foreach ($logFiles as $i => $logFile) {
                $content = file_get_contents($logFile);
                $lines = explode("\n", $content);
                
                // Simulate log processing
                $errorLines = array_filter($lines, function($line) {
                    return strpos($line, 'ERROR') !== false;
                });
                
                $processedFile = $this->testDir . "/processed_sync_{$i}.txt";
                file_put_contents($processedFile, implode("\n", $errorLines));
                
                // Clean up
                unlink($processedFile);
            }
        });
        
        // Async benchmark
        $asyncTime = $this->measureTime(function() use ($logFiles) {
            Async::run(function() use ($logFiles) {
                $operations = [];
                foreach ($logFiles as $i => $logFile) {
                    $operations[] = function() use ($i, $logFile) {
                        $content = Async::await(Async::readFile($logFile));
                        $lines = explode("\n", $content);
                        
                        // Simulate log processing
                        $errorLines = array_filter($lines, function($line) {
                            return strpos($line, 'ERROR') !== false;
                        });
                        
                        $processedFile = $this->testDir . "/processed_async_{$i}.txt";
                        Async::await(Async::writeFile($processedFile, implode("\n", $errorLines)));
                        
                        // Clean up
                        Async::await(Async::deleteFile($processedFile));
                        
                        return count($errorLines);
                    };
                }
                Async::await(Async::concurrent($operations, 5));
            });
        });
        
        $this->printComparison("Log Processing", $syncTime, $asyncTime);
        
        // Clean up log files
        foreach ($logFiles as $file) {
            if (file_exists($file)) unlink($file);
        }
        echo "\n";
    }

    private function generateLogContent(int $lines): string
    {
        $levels = ['INFO', 'DEBUG', 'WARN', 'ERROR'];
        $messages = [
            'User logged in',
            'Database connection established',
            'Cache miss for key: user_123',
            'Invalid password attempt',
            'File not found: config.xml',
            'Memory usage: 85%',
            'Request processed successfully'
        ];
        
        $content = [];
        for ($i = 0; $i < $lines; $i++) {
            $timestamp = date('Y-m-d H:i:s', time() - rand(0, 86400));
            $level = $levels[array_rand($levels)];
            $message = $messages[array_rand($messages)];
            $content[] = "[{$timestamp}] {$level}: {$message}";
        }
        
        return implode("\n", $content);
    }

    private function measureTime(callable $operation): float
    {
        $start = microtime(true);
        $operation();
        return (microtime(true) - $start) * 1000; // Convert to milliseconds
    }

    private function printComparison(string $testName, float $syncTime, float $asyncTime)
    {
        if ($asyncTime <= 0) {
            printf("  %-25s | Sync: %8.1fms | Async: FAILED\n", $testName, $syncTime);
            return;
        }
        
        $improvement = (($syncTime - $asyncTime) / $syncTime) * 100;
        $speedRatio = $asyncTime > 0 ? $syncTime / $asyncTime : 0;
        
        if ($improvement > 0) {
            printf("  %-25s | Sync: %8.1fms | Async: %8.1fms | 🚀 %.1f%% faster (%.2fx)\n", 
                $testName, $syncTime, $asyncTime, $improvement, $speedRatio);
        } else {
            printf("  %-25s | Sync: %8.1fms | Async: %8.1fms | ⚠️  %.1f%% slower\n", 
                $testName, $syncTime, $asyncTime, abs($improvement));
        }
    }

    public function __destruct()
    {
        // Clean up test directory
        if (is_dir($this->testDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
            }
            @rmdir($this->testDir);
        }
    }
}

// Run the benchmark
$benchmark = new RealisticAsyncBenchmark();
$benchmark->runAllBenchmarks();