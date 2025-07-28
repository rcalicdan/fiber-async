<?php


use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\RetryConfig;


describe('HTTP Client Retry Logic', function () {

    test('gives up after max retries with exponential backoff', function () {
        $maxRetries = 2; // 1 initial attempt + 2 retries = 3 total
        $baseDelay = 0.2;
        $backoffMultiplier = 2.0;
        $e = null;

        $start = microtime(true);
        try {
            run(fn () => await(
                http()
                    ->retry($maxRetries, $baseDelay, $backoffMultiplier)
                    ->get('https://httpbin.org/status/503')
            ));
        } catch (HttpException $exception) {
            $e = $exception;
        }
        $duration = microtime(true) - $start;

        expect($e)->toBeInstanceOf(HttpException::class);
        expect($e->getMessage())->toContain('after 3 attempts');

        // Total delay is at least (0.2s for first retry) + (0.2s * 2.0 for second retry) = 0.6s
        $expectedMinDuration = $baseDelay + ($baseDelay * $backoffMultiplier);
        expect($duration)->toBeGreaterThan($expectedMinDuration);
    });

    test('does not retry on non-retryable client errors like 404', function () {
        $start = microtime(true);
        $response = run(fn () => await(http()->retry(3, 0.2)->get('https://httpbin.org/status/404')));
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(404);
        expect($duration)->toBeLessThan(3.0);
    });

    test('custom RetryConfig is respected', function () {
        $start = microtime(true);
        $customRetryConfig = new RetryConfig(
            maxRetries: 1, 
            baseDelay: 0.2,
            retryableStatusCodes: [418] 
        );
        $e = null;

        try {
            run(fn () => await(
                http()
                    ->retryWith($customRetryConfig)
                    ->get('https://httpbin.org/status/418')
            ));
        } catch (HttpException $exception) {
            $e = $exception;
        }
        $duration = microtime(true) - $start;

        expect($e)->toBeInstanceOf(HttpException::class);
        expect($e->getMessage())->toContain('after 2 attempts');
        expect($duration)->toBeGreaterThan(0.2);
    });

    test('retries on a DNS failure exception', function () {
        $maxRetries = 1; 
        $baseDelay = 0.1;
        $start = microtime(true);
        $e = null;

        try {
            run(fn () => await(
                http()
                    ->retry($maxRetries, $baseDelay)
                    ->get('https://this-domain-will-not-resolve-ever.test')
            ));
        } catch (HttpException $exception) {
            $e = $exception;
        }
        $duration = microtime(true) - $start;

        expect($e)->toBeInstanceOf(HttpException::class);
        expect($duration)->toBeGreaterThan(0.08);
        expect($e->getMessage())->toContain("after " . ($maxRetries + 1) . " attempts");
    });
});