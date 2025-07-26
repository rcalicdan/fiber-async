<?php
namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Http\Response; 
use Rcalicdan\FiberAsync\Http\StreamingResponse; 

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

    public function headers(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
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
        $this->body = $content;
        return $this;
    }

    public function json(array $data): self
    {
        $this->body = json_encode($data);
        $this->contentType('application/json');
        return $this;
    }

    public function form(array $data): self
    {
        $this->body = http_build_query($data);
        $this->contentType('application/x-www-form-urlencoded');
        return $this;
    }

    public function multipart(array $data): self
    {
        $this->options['multipart'] = $data;
        $this->body = null;
        return $this;
    }

    /**
     * @return PromiseInterface<StreamingResponse>
     */
    public function stream(string $url, ?callable $onChunk = null): PromiseInterface
    {
        $options = $this->buildCurlOptions('GET', $url);
        return $this->handler->stream($url, $options, $onChunk);
    }

    /**
     * @return PromiseInterface<array>
     */
    public function download(string $url, string $destination): PromiseInterface
    {
        $options = $this->buildCurlOptions('GET', $url);
        return $this->handler->download($url, $destination, $options);
    }

    /**
     * @return PromiseInterface<StreamingResponse>
     */
    public function streamPost(string $url, $body = null, ?callable $onChunk = null): PromiseInterface
    {
        if ($body !== null) {
            $this->body($body);
        }
        $options = $this->buildCurlOptions('POST', $url);
        $options[CURLOPT_HEADER] = false;
        return $this->handler->stream($url, $options, $onChunk);
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        if ($query) {
            $url .= (strpos($url, '?') !== false ? '&' : '?').http_build_query($query);
        }
        return $this->send('GET', $url);
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        if ($data && ! $this->body && ! isset($this->options['multipart'])) {
            $this->json($data);
        }
        return $this->send('POST', $url);
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        if ($data && ! $this->body && ! isset($this->options['multipart'])) {
            $this->json($data);
        }
        return $this->send('PUT', $url);
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->send('DELETE', $url);
    }

    /**
     * @return PromiseInterface<Response>
     */
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
        } elseif ($this->body !== null) {
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