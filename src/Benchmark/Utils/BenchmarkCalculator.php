<?php

namespace Rcalicdan\FiberAsync\Benchmark\Utils;

class BenchmarkCalculator
{
    public function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    public function percentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);

        if (floor($index) == $index) {
            return $values[(int) $index];
        }

        $lower = $values[floor($index)];
        $upper = $values[ceil($index)];

        return $lower + ($upper - $lower) * ($index - floor($index));
    }

    public function calculateStatistics(array $values): array
    {
        if (empty($values)) {
            return [];
        }

        sort($values);
        $count = count($values);
        $mean = array_sum($values) / $count;

        $variance = array_sum(array_map(function ($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / $count;

        return [
            'mean' => $mean,
            'median' => $this->median($values),
            'min' => min($values),
            'max' => max($values),
            'std_dev' => sqrt($variance),
            'variance' => $variance,
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            'coefficient_of_variation' => $mean != 0 ? sqrt($variance) / $mean : 0,
        ];
    }
}
