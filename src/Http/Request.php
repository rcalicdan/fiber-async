<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\Http\HttpHandler;

class Request
{
    private HttpHandler $handler;
    private array $headers = [];
    private array $options = [];
    private ?string $body = null;
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $followRedirects = true;
    private int $maxRedirects = 5;
    private bool $verifySSL = true;
    private ?string $userAgent = null;
    private ?array $auth = null;
    private ?RetryConfig $retryConfig = null;

    public function __construct(HttpHandler $handler)
    {
        $this->handler = $handler;
        $this->userAgent = 'FiberAsync-HTTP/1.0';
    }

    /**
     * Set request headers
     */
    public function headers(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Set a single header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Set Content-Type header
     */
    public function contentType(string $type): self
    {
        return $this->header('Content-Type', $type);
    }

    /**
     * Set Accept header
     */
    public function accept(string $type): self
    {
        return $this->header('Accept', $type);
    }

    /**
     * Set Authorization header
     */
    public function bearerToken(string $token): self
    {
        return $this->header('Authorization', "Bearer {$token}");
    }

    /**
     * Set basic authentication
     */
    public function basicAuth(string $username, string $password): self
    {
        $this->auth = ['basic', $username, $password];

        return $this;
    }

    /**
     * Set request timeout
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set connection timeout
     */
    public function connectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;

        return $this;
    }

    /**
     * Configure redirect behavior
     */
    public function redirects(bool $follow = true, int $max = 5): self
    {
        $this->followRedirects = $follow;
        $this->maxRedirects = $max;

        return $this;
    }

    /**
     * Configure retry behavior
     */
    public function retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0): self
    {
        $this->retryConfig = new RetryConfig(
            maxRetries: $maxRetries,
            baseDelay: $baseDelay,
            backoffMultiplier: $backoffMultiplier
        );

        return $this;
    }

    /**
     * Configure advanced retry behavior
     */
    public function retryWith(RetryConfig $config): self
    {
        $this->retryConfig = $config;

        return $this;
    }

    /**
     * Disable retry behavior
     */
    public function noRetry(): self
    {
        $this->retryConfig = null;

        return $this;
    }

    /**
     * Configure SSL verification
     */
    public function verifySSL(bool $verify = true): self
    {
        $this->verifySSL = $verify;

        return $this;
    }

    /**
     * Set User-Agent
     */
    public function userAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Set raw body content
     */
    public function body(string $content): self
    {
        $this->body = $content;

        return $this;
    }

    /**
     * Set JSON body
     */
    public function json(array $data): self
    {
        $this->body = json_encode($data);
        $this->contentType('application/json');

        return $this;
    }

    /**
     * Set form data
     */
    public function form(array $data): self
    {
        $this->body = http_build_query($data);
        $this->contentType('application/x-www-form-urlencoded');

        return $this;
    }

    /**
     * Set multipart form data
     */
    public function multipart(array $data): self
    {
        $this->options['multipart'] = $data;

        return $this;
    }

    /**
     * Perform GET request
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        if ($query) {
            $url .= (strpos($url, '?') !== false ? '&' : '?').http_build_query($query);
        }

        return $this->send('GET', $url);
    }

    /**
     * Perform POST request
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        if ($data && ! $this->body) {
            $this->json($data);
        }

        return $this->send('POST', $url);
    }

    /**
     * Perform PUT request
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        if ($data && ! $this->body) {
            $this->json($data);
        }

        return $this->send('PUT', $url);
    }

    /**
     * Perform DELETE request
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->send('DELETE', $url);
    }

    /**
     * Send the request with specified method
     */
    public function send(string $method, string $url): PromiseInterface
    {
        $options = $this->buildCurlOptions($method, $url);

        if ($this->retryConfig) {
            return $this->handler->fetchWithRetry($url, $options, $this->retryConfig);
        }

        return $this->handler->fetch($url, $options);
    }

    /**
     * Build cURL options array
     */
    private function buildCurlOptions(string $method, string $url): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        $this->addHeaderOptions($options);
        $this->addBodyOptions($options);
        $this->addAuthenticationOptions($options);

        // Merge with custom options
        return array_merge($options, $this->options);
    }

    private function addHeaderOptions(array &$options): void
    {
        if ($this->headers) {
            $headerStrings = [];
            foreach ($this->headers as $name => $value) {
                $headerStrings[] = "{$name}: {$value}";
            }
            $options[CURLOPT_HTTPHEADER] = $headerStrings;
        }
    }

    private function addBodyOptions(array &$options): void
    {
        if ($this->body) {
            $options[CURLOPT_POSTFIELDS] = $this->body;
        }
    }

    private function addAuthenticationOptions(array &$options): void
    {
        if ($this->auth) {
            [$type, $username, $password] = $this->auth;
            if ($type === 'basic') {
                $options[CURLOPT_USERPWD] = "{$username}:{$password}";
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            }
        }
    }
}
