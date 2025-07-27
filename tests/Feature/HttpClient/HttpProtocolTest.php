<?php

use Rcalicdan\FiberAsync\Api\AsyncHttp;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A custom, test-only HttpHandler that intercepts the final cURL handle
 * to extract the negotiated HTTP protocol version.
 * 
 * It is marked as readonly to correctly extend the readonly parent HttpHandler.
 */
readonly class TestHttpHandler extends HttpHandler
{
    /**
     * Overrides the default sendRequest to inject our protocol-sniffing logic.
     *
     * @param string $url
     * @param array $curlOptions
     * @return PromiseInterface
     */
    public function sendRequest(string $url, array $curlOptions): PromiseInterface
    {
        $testPromise = new CancellablePromise();
        $requestId = null;

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOptions,
            function ($error, $responseBody, $httpCode, $headers = []) use ($testPromise, &$requestId, $curlOptions) {
                if ($testPromise->isCancelled()) {
                    return;
                }
                
            
                $tempHandle = curl_init();
                curl_setopt_array($tempHandle, $curlOptions);
                curl_exec($tempHandle); 
                
                $protocolVersion = curl_getinfo($tempHandle, CURLINFO_HTTP_VERSION);
                curl_close($tempHandle);


                if ($error) {
                    $testPromise->reject(new \Exception($error));
                } else {
                    $testPromise->resolve((object)[
                        'response' => new Response($responseBody, $httpCode, $headers),
                        'protocol' => $protocolVersion,
                    ]);
                }
            }
        );
        
        $testPromise->setCancelHandler(function () use (&$requestId) {
            if ($requestId) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        
        $multiHandle = curl_multi_init();
        $ch = curl_init();
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
                usleep(100); // Prevent busy-waiting
            }
        }
        
        $info = curl_multi_info_read($multiHandle);
        $handle = $info['handle'];
        $responseBody = curl_multi_getcontent($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $protocol = curl_getinfo($handle, CURLINFO_HTTP_VERSION);
        
        curl_multi_remove_handle($multiHandle, $handle);
        curl_multi_close($multiHandle);
        
        $promise = new CancellablePromise();
        $promise->resolve((object)['response' => new Response($responseBody, $httpCode, []), 'protocol' => $protocol]);
        return $promise;
    }
}


/**
 * Sets up our custom HttpHandler for testing protocol versions.
 */
function setupTestHandler()
{
    AsyncHttp::setInstance(new TestHttpHandler());
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
        
        if (defined('CURL_HTTP_VERSION_3')) {
             expect($result->protocol)->not->toBe(CURL_HTTP_VERSION_3);
        }
       
        expect($result->protocol)->toBeIn([CURL_HTTP_VERSION_1_1, CURL_HTTP_VERSION_2]);
    });
});