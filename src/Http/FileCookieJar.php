<?php

namespace Rcalicdan\FiberAsync\Http;

/**
 * File-based persistent cookie jar implementation.
 */
class FileCookieJar extends CookieJar
{
    private string $filename;
    private bool $storeSessionCookies;

    public function __construct(string $filename, bool $storeSessionCookies = false)
    {
        $this->filename = $filename;
        $this->storeSessionCookies = $storeSessionCookies;
        $this->load();
    }

    public function __destruct()
    {
        $this->save();
    }

    public function setCookie(Cookie $cookie): void
    {
        parent::setCookie($cookie);
        $this->save();
    }

    public function clear(): void
    {
        parent::clear();
        $this->save();
    }

    /**
     * Load cookies from file.
     */
    private function load(): void
    {
        if (!file_exists($this->filename)) {
            return;
        }

        $content = file_get_contents($this->filename);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $cookieData) {
            if (!is_array($cookieData)) {
                continue;
            }

            $cookie = new Cookie(
                $cookieData['name'] ?? '',
                $cookieData['value'] ?? '',
                $cookieData['expires'] ?? null,
                $cookieData['domain'] ?? null,
                $cookieData['path'] ?? null,
                $cookieData['secure'] ?? false,
                $cookieData['httpOnly'] ?? false,
                $cookieData['maxAge'] ?? null,
                $cookieData['sameSite'] ?? null
            );

            if (!$cookie->isExpired()) {
                $this->cookies[] = $cookie;
            }
        }
    }

    /**
     * Save cookies to file.
     */
    private function save(): void
    {
        $data = [];
        
        foreach ($this->cookies as $cookie) {
            // Skip session cookies if not storing them
            if (!$this->storeSessionCookies && $cookie->getExpires() === null && $cookie->getMaxAge() === null) {
                continue;
            }

            $data[] = [
                'name' => $cookie->getName(),
                'value' => $cookie->getValue(),
                'expires' => $cookie->getExpires(),
                'domain' => $cookie->getDomain(),
                'path' => $cookie->getPath(),
                'secure' => $cookie->isSecure(),
                'httpOnly' => $cookie->isHttpOnly(),
                'maxAge' => $cookie->getMaxAge(),
                'sameSite' => $cookie->getSameSite(),
            ];
        }

        $dir = dirname($this->filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->filename, json_encode($data, JSON_PRETTY_PRINT));
    }
}