<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\CookieJarInterface;

/**
 * In-memory cookie jar implementation.
 */
class CookieJar implements CookieJarInterface
{
    /** @var Cookie[] */
    protected array $cookies = [];

    public function setCookie(Cookie $cookie): void
    {
        // Remove any existing cookie with the same name, domain, and path
        $this->cookies = array_filter($this->cookies, function (Cookie $existingCookie) use ($cookie) {
            return !($existingCookie->getName() === $cookie->getName() &&
                    $existingCookie->getDomain() === $cookie->getDomain() &&
                    $existingCookie->getPath() === $cookie->getPath());
        });

        // Add the new cookie if it's not expired
        if (!$cookie->isExpired()) {
            $this->cookies[] = $cookie;
        }
    }

    public function getCookies(string $domain, string $path, bool $isSecure = false): array
    {
        $this->clearExpired();

        return array_filter($this->cookies, function (Cookie $cookie) use ($domain, $path, $isSecure) {
            return $cookie->matches($domain, $path, $isSecure);
        });
    }

    public function getAllCookies(): array
    {
        $this->clearExpired();
        return $this->cookies;
    }

    public function clearExpired(): void
    {
        $this->cookies = array_filter($this->cookies, function (Cookie $cookie) {
            return !$cookie->isExpired();
        });
    }

    public function clear(): void
    {
        $this->cookies = [];
    }

    public function getCookieHeader(string $domain, string $path, bool $isSecure = false): string
    {
        $matchingCookies = $this->getCookies($domain, $path, $isSecure);
        
        if (empty($matchingCookies)) {
            return '';
        }

        return implode('; ', array_map(function (Cookie $cookie) {
            return $cookie->toCookieHeader();
        }, $matchingCookies));
    }

    /**
     * Create a cookie jar from an array of Set-Cookie headers.
     *
     * @param string[] $setCookieHeaders Array of Set-Cookie header values
     * @return static
     */
    public static function fromSetCookieHeaders(array $setCookieHeaders): self
    {
        $jar = new self();
        
        foreach ($setCookieHeaders as $header) {
            $cookie = Cookie::fromSetCookieHeader($header);
            if ($cookie !== null) {
                $jar->setCookie($cookie);
            }
        }

        return $jar;
    }
}