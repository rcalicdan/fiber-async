<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\UriInterface;

class Uri implements UriInterface
{
    protected string $scheme = '';
    protected string $host = '';
    protected ?int $port = null;
    protected string $path = '/';
    protected string $query = '';
    protected string $fragment = '';
    protected string $userInfo = '';

    public function __construct(string $uri)
    {
        $parts = parse_url($uri);

        if (!$parts) {
            throw new \InvalidArgumentException("Invalid URI: $uri");
        }

        $this->scheme = $parts['scheme'] ?? '';
        $this->host = $parts['host'] ?? '';
        $this->port = $parts['port'] ?? null;
        $this->path = $parts['path'] ?? '/';
        $this->query = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';

        if (isset($parts['user'])) {
            $this->userInfo = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '');
        }
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        $authority = $this->getUserInfo() !== '' ? $this->getUserInfo() . '@' : '';
        $authority .= $this->host;

        if ($this->port !== null && !in_array($this->port, [80, 443])) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $clone = clone $this;
        $clone->scheme = $scheme;
        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $clone = clone $this;
        $clone->userInfo = $user . ($password !== null ? ':' . $password : '');
        return $clone;
    }

    public function withHost(string $host): UriInterface
    {
        $clone = clone $this;
        $clone->host = $host;
        return $clone;
    }

    public function withPort(?int $port): UriInterface
    {
        $clone = clone $this;
        $clone->port = $port;
        return $clone;
    }

    public function withPath(string $path): UriInterface
    {
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    public function withQuery(string $query): UriInterface
    {
        $clone = clone $this;
        $clone->query = $query;
        return $clone;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $clone = clone $this;
        $clone->fragment = $fragment;
        return $clone;
    }

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme) {
            $uri .= $this->scheme . '://';
        }

        $uri .= $this->getAuthority();

        if ($this->path) {
            $uri .= $this->path;
        }

        if ($this->query) {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment) {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }
}
