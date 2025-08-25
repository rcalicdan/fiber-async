<?php

/**
 * Generate an array of random float numbers.
 *
 * @param  float  $min  Minimum value (inclusive).
 * @param  float  $max  Maximum value (inclusive).
 * @param  int  $count  How many numbers to generate.
 * @param  int  $precision  Number of decimal places (default: 6).
 * @return float[]
 */
function random_floats(float $min, float $max, int $count, int $precision = 6): array
{
    if ($min > $max) {
        throw new InvalidArgumentException('Min cannot be greater than max.');
    }
    if ($count <= 0) {
        throw new InvalidArgumentException('Count must be greater than 0.');
    }

    $results = [];
    $scale = pow(10, $precision);

    for ($i = 0; $i < $count; $i++) {
        $rand = random_int((int) ($min * $scale), (int) ($max * $scale));
        $results[] = $rand / $scale;
    }

    return $results;
}

/**
 * Generate one random float between min and max.
 *
 * @param  float  $min  Minimum value (inclusive).
 * @param  float  $max  Maximum value (inclusive).
 * @param  int  $precision  Number of decimal places (default: 6).
 */
function random_float(float $min, float $max, int $precision = 6): float
{
    if ($min > $max) {
        throw new InvalidArgumentException('Min cannot be greater than max.');
    }

    $scale = pow(10, $precision);
    $rand = random_int((int) ($min * $scale), (int) ($max * $scale));

    return $rand / $scale;
}
