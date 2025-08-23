<?php

namespace Rcalicdan\FiberAsync\Http\Testing;

/**
 * Builder for creating mocked requests with fluent interface.
 */
class MockRequestBuilder
{
    private TestingHttpHandler $handler;
    private MockedRequest $request;

    public function __construct(TestingHttpHandler $handler, string $method = '*')
    {
        $this->handler = $handler;
        $this->request = new MockedRequest($method);
    }

    public function url(string $pattern): self
    {
        $this->request->setUrlPattern($pattern);
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->request->addHeaderMatcher($name, $value);
        return $this;
    }

    public function withBody(string $pattern): self
    {
        $this->request->setBodyMatcher($pattern);
        return $this;
    }

    public function withJson(array $data): self
    {
        $this->request->setJsonMatcher($data);
        return $this;
    }

    public function respondWith(int $status = 200): self
    {
        $this->request->setStatusCode($status);
        return $this;
    }

    public function body(string $body): self
    {
        $this->request->setBody($body);
        return $this;
    }

    public function json(array $data): self
    {
        $this->request->setBody(json_encode($data));
        $this->request->addResponseHeader('Content-Type', 'application/json');
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->request->addResponseHeader($name, $value);
        return $this;
    }

    public function delay(float $seconds): self
    {
        $this->request->setDelay($seconds);
        return $this;
    }

    public function fail(string $error = "Mocked request failure"): self
    {
        $this->request->setError($error);
        return $this;
    }

    public function timeout(float $seconds = 30.0): self
    {
        $this->request->setTimeout($seconds);
        return $this;
    }

    public function retryableFailure(string $error = "Connection failed"): self
    {
        $this->request->setError($error);
        $this->request->setRetryable(true);
        return $this;
    }

    public function persistent(): self
    {
        $this->request->setPersistent(true);
        return $this;
    }

    public function downloadFile(string $content, ?string $filename = null, string $contentType = 'application/octet-stream'): self
    {
        $this->request->setBody($content);
        $this->request->addResponseHeader('Content-Type', $contentType);
        $this->request->addResponseHeader('Content-Length', (string)strlen($content));

        if ($filename !== null) {
            $this->request->addResponseHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        return $this;
    }

    public function downloadLargeFile(int $sizeInKB = 100, ?string $filename = null): self
    {
        $content = str_repeat('MOCK_FILE_DATA_', $sizeInKB * 64);
        return $this->downloadFile($content, $filename, 'application/octet-stream');
    }

    public function register(): void
    {
        $this->handler->addMockedRequest($this->request);
    }
}
