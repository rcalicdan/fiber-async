<?php

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Streaming and Downloads', function () {

    test('http_stream receives data in chunks', function () {
        $chunkCount = 0;
        $fullContent = '';
        $lines = [];

        $response = run(function () use (&$chunkCount, &$fullContent, &$lines) {
            return await(http_stream(
                'https://httpbin.org/stream/5',
                [],
                function (string $chunk) use (&$chunkCount, &$fullContent, &$lines) {
                    $chunkCount++;
                    $fullContent .= $chunk;
                    
                    // Process potential multiple lines in a single chunk
                    $receivedLines = explode("\n", trim($chunk));
                    foreach ($receivedLines as $line) {
                        if (trim($line) !== '') {
                            $lines[] = json_decode($line, true);
                        }
                    }
                }
            ));
        });

        expect($chunkCount)->toBeGreaterThanOrEqual(1); 
        expect($lines)->toHaveCount(5); 
        expect($lines[0])->toHaveKeys(['id', 'url', 'args', 'headers']); 
        expect($response->body())->toEqual($fullContent);
    });

    test('http_download saves a file correctly', function () {
        $destination = tempnam(sys_get_temp_dir(), 'download_test');
        $imageUrl = 'https://httpbin.org/image/png';

        $result = run(fn() => await(http_download($imageUrl, $destination)));

        expect($result['file'])->toBe($destination);
        expect($result['status'])->toBe(200);
        expect(file_exists($destination))->toBeTrue();
        expect(filesize($destination))->toBeGreaterThan(100);

        $magicNumber = file_get_contents($destination, false, null, 0, 8);
        expect(bin2hex($magicNumber))->toBe('89504e470d0a1a0a');

        unlink($destination);
    });
});