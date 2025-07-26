<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\RequestInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\UriInterface;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

class Request extends Message implements RequestInterface
{
    private HttpHandler $handler;
    private string $method = 'GET';
    private ?string $requestTarget = null;
    private UriInterface $uri;
    private array $options = [];
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $followRedirects = true;
    private int $maxRedirects = 5;
    private bool $verifySSL = true;
    private ?string $userAgent = null;
    private ?array $auth = null;
    private ?RetryConfig $retryConfig = null;

    public function __construct(HttpHandler $handler, string $method = 'GET', $uri = '', array $headers = [], $body = null, string $version = '1.1')
    {
        $this->handler = $handler;
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->setHeaders($headers);
        $this->protocol = $version;
        $this->userAgent = 'FiberAsync-HTTP/1.0';

        if ($body !== '' && $body !== null) {
            $this->body = $body instanceof Stream ? $body : new Stream(fopen('php://temp', 'r+'), null);
            if (!($body instanceof Stream)) {
                $this->body->write($body);
                $this->body->rewind();
            }
        } else {
            $this->body = new Stream(fopen('php://temp', 'r+'), null);
        }
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        if ($this->requestTarget === $requestTarget) {
            return $this;
        }

        $new = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $method = strtoupper($method);
        if ($this->method === $method) {
            return $this;
        }

        $new = clone $this;
        $new->method = $method;
        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || !isset($this->headerNames['host'])) {
            $new->updateHostFromUri();
        }

        return $new;
    }

    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    public function contentType(string $type): self
    {
        return $this->header('Content-Type', $type);
    }

    public function accept(string $type): self
    {
        return $this->header('Accept', $type);
    }

    public function bearerToken(string $token): self
    {
        return $this->header('Authorization', "Bearer {$token}");
    }

    public function basicAuth(string $username, string $password): self
    {
        $this->auth = ['basic', $username, $password];
        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function connectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function redirects(bool $follow = true, int $max = 5): self
    {
        $this->followRedirects = $follow;
        $this->maxRedirects = $max;
        return $this;
    }

    public function retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0): self
    {
        $this->retryConfig = new RetryConfig(
            maxRetries: $maxRetries,
            baseDelay: $baseDelay,
            backoffMultiplier: $backoffMultiplier
        );
        return $this;
    }

    public function retryWith(RetryConfig $config): self
    {
        $this->retryConfig = $config;
        return $this;
    }

    public function noRetry(): self
    {
        $this->retryConfig = null;
        return $this;
    }

    public function verifySSL(bool $verify = true): self
    {
        $this->verifySSL = $verify;
        return $this;
    }

    public function userAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function body(string $content): self
    {
        $this->body = new Stream(fopen('php://temp', 'r+'), null);
        $this->body->write($content);
        $this->body->rewind();
        return $this;
    }

    public function json(array $data): self
    {
        $this->body(json_encode($data));
        $this->contentType('application/json');
        return $this;
    }

    public function form(array $data): self
    {
        $this->body(http_build_query($data));
        $this->contentType('application/x-www-form-urlencoded');
        return $this;
    }

    public function multipart(array $data): self
    {
        $this->options['multipart'] = $data;
        return $this;
    }

    public function stream(string $url, ?callable $onChunk = null): PromiseInterface
    {
        $options = $this->buildCurlOptions('GET', $url);
        return $this->handler->stream($url, $options, $onChunk);
    }

    public function download(string $url, string $destination): PromiseInterface
    {
        $options = $this->buildCurlOptions('GET', $url);
        return $this->handler->download($url, $destination, $options);
    }

    public function streamPost(string $url, $body = null, ?callable $onChunk = null): PromiseInterface
    {
        if ($body !== null) {
            $this->body($body);
        }
        $options = $this->buildCurlOptions('POST', $url);
        $options[CURLOPT_HEADER] = false;
        return $this->handler->stream($url, $options, $onChunk);
    }

    public function get(string $url, array $query = []): PromiseInterface
    {
        if ($query) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query);
        }
        return $this->send('GET', $url);
    }

    public function post(string $url, array $data = []): PromiseInterface
    {
        if ($data && !$this->body->getSize() && !isset($this->options['multipart'])) {
            $this->json($data);
        }
        return $this->send('POST', $url);
    }

    public function put(string $url, array $data = []): PromiseInterface
    {
        if ($data && !$this->body->getSize() && !isset($this->options['multipart'])) {
            $this->json($data);
        }
        return $this->send('PUT', $url);
    }

    public function delete(string $url): PromiseInterface
    {
        return $this->send('DELETE', $url);
    }

    public function send(string $method, string $url): PromiseInterface
    {
        $options = $this->buildCurlOptions($method, $url);
        if ($this->retryConfig) {
            return $this->handler->fetchWithRetry($url, $options, $this->retryConfig);
        }
        return $this->handler->fetch($url, $options);
    }

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

        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $this->addHeaderOptions($options);
        $this->addBodyOptions($options);
        $this->addAuthenticationOptions($options);

        foreach ($this->options as $key => $value) {
            if (is_int($key)) {
                $options[$key] = $value;
            }
        }
        return $options;
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
        if (isset($this->options['multipart'])) {
            $options[CURLOPT_POSTFIELDS] = $this->options['multipart'];
        } elseif ($this->body->getSize() > 0) {
            $options[CURLOPT_POSTFIELDS] = (string) $this->body;
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

    private function updateHostFromUri(): void
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return;
        }

        if (($port = $this->uri->getPort()) !== null) {
            $host .= ':' . $port;
        }

        if (isset($this->headerNames['host'])) {
            $header = $this->headerNames['host'];
        } else {
            $header = 'Host';
            $this->headerNames['host'] = 'Host';
        }
        $this->headers[$header] = [$host];
    }
}