<?php

namespace Tests\Helpers;

use Exception;

class BenchmarkHelper
{
    public static function measureTime(callable $callback): array
    {
        $start = microtime(true);
        $memoryStart = memory_get_usage(true);
        
        try {
            $result = $callback();
            $end = microtime(true);
            $memoryEnd = memory_get_usage(true);
            
            return [
                'duration' => round($end - $start, 4),
                'result' => $result,
                'memory_used' => $memoryEnd - $memoryStart,
                'success' => true
            ];
        } catch (Exception $e) {
            $end = microtime(true);
            $memoryEnd = memory_get_usage(true);
            
            return [
                'duration' => round($end - $start, 4),
                'error' => $e->getMessage(),
                'memory_used' => $memoryEnd - $memoryStart,
                'success' => false
            ];
        }
    }
    
    public static function comparePerformance(array $sequential, array $concurrent): array
    {
        if (!$sequential['success'] || !$concurrent['success']) {
            return ['error' => 'One or both tests failed'];
        }
        
        $improvement = round($sequential['duration'] / $concurrent['duration'], 2);
        $timeSaved = round($sequential['duration'] - $concurrent['duration'], 4);
        
        return [
            'sequential_time' => $sequential['duration'],
            'concurrent_time' => $concurrent['duration'],
            'improvement_factor' => $improvement,
            'time_saved' => $timeSaved,
            'memory_difference' => $concurrent['memory_used'] - $sequential['memory_used']
        ];
    }
    
    public static function formatResults(array $comparison): string
    {
        return sprintf(
            "Sequential: %ss | Concurrent: %ss | Improvement: %sx faster | Time saved: %ss",
            $comparison['sequential_time'],
            $comparison['concurrent_time'], 
            $comparison['improvement_factor'],
            $comparison['time_saved']
        );
    }
}