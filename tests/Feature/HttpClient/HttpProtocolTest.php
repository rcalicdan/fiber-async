<?php

namespace Tests\Feature\HttpClient;

use Rcalicdan\FiberAsync\Api\AsyncHttp;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A custom, test-only HttpHandler that performs a real, but blocking, network
 * request to accurately determine the negotiated HTTP protocol version from cURL.
 * This is acceptable for a feature test where we need to inspect low-level details.
 */
class HttpProtocolTest extends HttpHandler
{
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        $multiHandle = curl_multi_init();
        $ch = curl_init();
        
        $curlOptions[CURLOPT_HEADER] = true;
        
        curl_setopt_array($ch, $curlOptions);
        curl_multi_add_handle($multiHandle, $ch);

        $active = null;
        do {
            $mrc = curl_multi_exec($multiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multiHandle) != -1) {
                do {
                    $mrc = curl_multi_exec($multiHandle, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            } else {
                usleep(100); 
            }
        }
        
        $info = curl_multi_info_read($multiHandle);
        $handle = $info['handle'];
        $fullResponse = curl_multi_getcontent($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $protocol = curl_getinfo($handle, CURLINFO_HTTP_VERSION); 
        $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        
        $headerStr = substr($fullResponse, 0, $headerSize);
        $body = substr($fullResponse, $headerSize);

        $headers = [];
        $headerLines = explode("\r\n", trim($headerStr));
        foreach ($headerLines as $line) {
            if (strpos($line, ': ') !== false) {
                list($key, $value) = explode(': ', $line, 2);
                $headers[$key] = $value;
            }
        }
        
        curl_multi_remove_handle($multiHandle, $handle);
        curl_multi_close($multiHandle);
        
        return resolve((object)[
            'response' => new Response($body, $httpCode, $headers),
            'protocol' => $protocol
        ]);
    }
}


/**
 * Helper function to set up our custom TestHttpHandler for each test.
 */
function setupTestHandler()
{
    AsyncHttp::setInstance(new HttpProtocolTest());
}


beforeEach(function () {
    resetEventLoop(); 
    AsyncHttp::reset();
});

describe('HTTP Protocol Negotiation', function () {

    test('performs a standard request using HTTP/1.1', function () {
        setupTestHandler();

        $result = run(function () {
            return await(
                http()
                    ->withProtocolVersion('1.1')
                    ->get('https://httpbin.org/get')
            );
        });
        
        // cURL constant for HTTP/1.1 is 2
        expect($result->protocol)->toBe(CURL_HTTP_VERSION_1_1);
        expect($result->response->ok())->toBeTrue();
    });

    test('performs a request using HTTP/2 when supported', function () {
        $curl_version = curl_version();
        if (!($curl_version['features'] & CURL_VERSION_HTTP2)) {
            test()->markTestSkipped('HTTP/2 is not supported in this cURL environment.');
        }

        setupTestHandler();

        $result = run(function () {
            return await(
                http()
                    ->withProtocolVersion('2.0')
                    ->get('https://www.google.com')
            );
        });

        // cURL constant for HTTP/2 is 3
        expect($result->protocol)->toBe(CURL_HTTP_VERSION_2);
        expect($result->response->ok())->toBeTrue();
    });

    test('gracefully falls back when HTTP/3 is requested but not supported', function () {
        setupTestHandler();
        
        $result = run(function () {
            return await(
                http()
                    ->withProtocolVersion('3.0')
                    ->get('https://www.cloudflare.com')
            );
        });
        
        expect($result->response->ok())->toBeTrue();
        
        // Prove it did NOT use HTTP/3 (since it's not supported)
        if (defined('CURL_HTTP_VERSION_3')) {
             expect($result->protocol)->not->toBe(CURL_HTTP_VERSION_3);
        }
       
        // Prove it fell back to a working protocol
        expect($result->protocol)->toBeIn([CURL_HTTP_VERSION_1_1, CURL_HTTP_VERSION_2]);
    });
});