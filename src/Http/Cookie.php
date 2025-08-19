<?php

namespace Rcalicdan\FiberAsync\Http;

/**
 * Represents an HTTP cookie with all its attributes.
 */
class Cookie
{
    private string $name;
    private string $value;
    private ?int $expires = null;
    private ?int $maxAge = null;
    private ?string $domain = null;
    private ?string $path = null;
    private bool $secure = false;
    private bool $httpOnly = false;
    private ?string $sameSite = null;

    public function __construct(
        string $name,
        string $value,
        ?int $expires = null,
        ?string $domain = null,
        ?string $path = null,
        bool $secure = false,
        bool $httpOnly = false,
        ?int $maxAge = null,
        ?string $sameSite = null
    ) {
        $this->name = $name;
        $this->value = $value;
        $this->expires = $expires;
        $this->domain = $domain;
        $this->path = $path;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
        $this->maxAge = $maxAge;
        $this->sameSite = $sameSite;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getExpires(): ?int
    {
        return $this->expires;
    }

    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }

    /**
     * Check if the cookie has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expires !== null) {
            return time() >= $this->expires;
        }

        if ($this->maxAge !== null) {
            // For max-age, we'd need to track when the cookie was set
            // This is a simplified check
            return $this->maxAge <= 0;
        }

        return false;
    }

    /**
     * Check if this cookie matches the given domain and path.
     */
    public function matches(string $domain, string $path, bool $isSecure = false): bool
    {
        // Check if cookie is expired
        if ($this->isExpired()) {
            return false;
        }

        // Check secure flag
        if ($this->secure && !$isSecure) {
            return false;
        }

        // Check domain
        if (!$this->matchesDomain($domain)) {
            return false;
        }

        // Check path
        if (!$this->matchesPath($path)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the cookie's domain matches the request domain.
     */
    private function matchesDomain(string $requestDomain): bool
    {
        if ($this->domain === null) {
            return true;
        }

        $cookieDomain = $this->domain;
        
        // Remove leading dot for domain comparison
        if (str_starts_with($cookieDomain, '.')) {
            $cookieDomain = substr($cookieDomain, 1);
        }

        // Exact match
        if ($cookieDomain === $requestDomain) {
            return true;
        }

        // Subdomain match (cookie domain starts with .)
        if (str_starts_with($this->domain, '.')) {
            return str_ends_with($requestDomain, '.' . $cookieDomain) || $requestDomain === $cookieDomain;
        }

        return false;
    }

    /**
     * Check if the cookie's path matches the request path.
     */
    private function matchesPath(string $requestPath): bool
    {
        if ($this->path === null || $this->path === '') {
            return true;
        }

        // Exact match
        if ($this->path === $requestPath) {
            return true;
        }

        // Path prefix match
        if (str_starts_with($requestPath, $this->path)) {
            // Ensure path ends with / or the next character in request path is /
            return str_ends_with($this->path, '/') || 
                   (isset($requestPath[strlen($this->path)]) && $requestPath[strlen($this->path)] === '/');
        }

        return false;
    }

    /**
     * Convert cookie to Set-Cookie header format.
     */
    public function toSetCookieHeader(): string
    {
        $parts = [$this->name . '=' . urlencode($this->value)];

        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $this->expires);
        }

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->path !== null) {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($this->sameSite !== null) {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }

    /**
     * Convert cookie to Cookie header format (name=value).
     */
    public function toCookieHeader(): string
    {
        return $this->name . '=' . $this->value;
    }

    /**
     * Create a Cookie from a Set-Cookie header value.
     */
    public static function fromSetCookieHeader(string $setCookieHeader): ?self
    {
        $parts = array_map('trim', explode(';', $setCookieHeader));
        
        if (empty($parts)) {
            return null;
        }

        // Parse name=value pair
        $nameValuePair = array_shift($parts);
        $equalPos = strpos($nameValuePair, '=');
        
        if ($equalPos === false) {
            return null;
        }

        $name = substr($nameValuePair, 0, $equalPos);
        $value = urldecode(substr($nameValuePair, $equalPos + 1));

        $expires = null;
        $maxAge = null;
        $domain = null;
        $path = null;
        $secure = false;
        $httpOnly = false;
        $sameSite = null;

        // Parse attributes
        foreach ($parts as $part) {
            if (strcasecmp($part, 'Secure') === 0) {
                $secure = true;
            } elseif (strcasecmp($part, 'HttpOnly') === 0) {
                $httpOnly = true;
            } elseif (str_contains($part, '=')) {
                [$attrName, $attrValue] = array_map('trim', explode('=', $part, 2));
                
                switch (strtolower($attrName)) {
                    case 'expires':
                        $expires = strtotime($attrValue);
                        break;
                    case 'max-age':
                        $maxAge = (int) $attrValue;
                        break;
                    case 'domain':
                        $domain = $attrValue;
                        break;
                    case 'path':
                        $path = $attrValue;
                        break;
                    case 'samesite':
                        $sameSite = $attrValue;
                        break;
                }
            }
        }

        return new self($name, $value, $expires, $domain, $path, $secure, $httpOnly, $maxAge, $sameSite);
    }

    public function __toString(): string
    {
        return $this->toCookieHeader();
    }
}