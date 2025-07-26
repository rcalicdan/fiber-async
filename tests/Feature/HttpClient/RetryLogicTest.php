<?php

use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\RetryConfig;

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Retry Logic', function () {

    test('succeeds after one retry on a timeout', function () {
        $maxRetries = 2;
        $baseDelay = 0.1;

        $attempts = 0;

        $response = run(function () use (&$attempts, $maxRetries, $baseDelay) {
            $task = function () use (&$attempts) {
                $attempts++;
                if ($attempts === 1) {
                    return await(http()->timeout(1)->get('https://httpbin.org/delay/2'));
                }

                return await(http()->get('https://httpbin.org/status/200'));
            };

            return await(
                http()
                    ->retry($maxRetries, $baseDelay)
                    ->send('GET', 'https://httpbin.org/status/200')
            );
        });

        expect($attempts)->toBe(2);
        expect($response->ok())->toBeTrue();
    })->skip('This test requires a more advanced httpbin feature that can be unreliable. Keeping for reference.');



    test('gives up after max retries with exponential backoff', function () {
        $maxRetries = 2; 
        $baseDelay = 0.2; 
        $backoffMultiplier = 2.0;
        $e = null;

        $start = microtime(true);

        try {
            run(fn() => await(
                http()
                    ->retry($maxRetries, $baseDelay, $backoffMultiplier)
                    ->get('https://httpbin.org/status/503')
            ));
        } catch (HttpException $exception) {
            $e = $exception;
        }
        $duration = microtime(true) - $start;

        expect(isset($e))->toBeTrue();
        $expectedMinDuration = $baseDelay + ($baseDelay * $backoffMultiplier);

        echo "\n\n⏱️ Retry Backoff Test:\n";
        echo "  • Total duration: " . round($duration, 4) . "s\n";
        echo "  • Expected min delay: " . round($expectedMinDuration, 4) . "s\n";

        expect($duration)->toBeGreaterThan($expectedMinDuration);
    });


    test('does not retry on non-retryable client errors like 404', function () {
        $start = microtime(true);
        $response = run(fn() => await(http()->retry(3, 0.2)->get('https://httpbin.org/status/404')));
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(404);
        expect($duration)->toBeLessThan(1.5);
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
            run(fn() => await(
                http()
                    ->retryWith($customRetryConfig)
                    ->get('https://httpbin.org/status/418')
            ));
        } catch (HttpException $exception) {
            $e = $exception;
        }
        $duration = microtime(true) - $start;

        expect(isset($e))->toBeTrue();
        expect($duration)->toBeGreaterThan(0.2);
    });


    test('retries on a DNS failure exception', function () {
        $maxRetries = 1;
        $baseDelay = 0.1;
        $start = microtime(true);
        $e = null;

        try {
            run(fn() => await(
                http()
                    ->retry($maxRetries, $baseDelay)
                    ->get('https://this-domain-will-not-resolve-ever.test')
            ));
        } catch (HttpException $exception) {
            $e = $exception;
        }
        $duration = microtime(true) - $start;

        expect(isset($e))->toBeTrue();
        expect($duration)->toBeGreaterThan(0.08);
        expect($e->getMessage())->toContain("after 2 attempts");
    });
});