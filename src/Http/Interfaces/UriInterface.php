<?php

namespace Rcalicdan\FiberAsync\Http\Interfaces;

interface UriInterface
{
    public function getScheme(): string;

    public function getAuthority(): string;

    public function getUserInfo(): string;

    public function getHost(): string;

    public function getPort(): ?int;

    public function getPath(): string;

    public function getQuery(): string;

    public function getFragment(): string;

    public function withScheme(string $scheme): UriInterface;

    public function withUserInfo(string $user, ?string $password = null): UriInterface;

    public function withHost(string $host): UriInterface;

    public function withPort(?int $port): UriInterface;

    public function withPath(string $path): UriInterface;

    public function withQuery(string $query): UriInterface;

    public function withFragment(string $fragment): UriInterface;

    public function __toString(): string;
}
