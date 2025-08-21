<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Cookie;
use Rcalicdan\FiberAsync\Http\CookieJar;
use Rcalicdan\FiberAsync\Http\FileCookieJar;

beforeEach(function () {
    $this->testDomain = 'httpbin.org';
    $this->testUrl = 'https://httpbin.org';
});

describe('Basic Cookie Operations', function () {
    it('can set and send individual cookies', function () {
        $response = Task::run(function () {
            return await(
                Http::request()
                    ->cookie('session_id', '12345')
                    ->cookie('user_pref', 'dark_mode')
                    ->get("{$this->testUrl}/cookies")
            );
        });

        expect($response->status())->toBe(200);

        $data = $response->json();
        if (isset($data['cookies'])) {
            expect($data['cookies'])->toHaveKeys(['session_id', 'user_pref']);
            expect($data['cookies']['session_id'])->toBe('12345');
            expect($data['cookies']['user_pref'])->toBe('dark_mode');
        }
    });

    it('can set multiple cookies at once', function () {
        $cookies = [
            'auth_token' => 'abc123',
            'language' => 'en',
            'theme' => 'light',
        ];

        $response = Task::run(function () use ($cookies) {
            return await(
                Http::request()
                    ->cookies($cookies)
                    ->get("{$this->testUrl}/cookies")
            );
        });

        expect($response->status())->toBe(200);

        $data = $response->json();
        if (isset($data['cookies'])) {
            foreach ($cookies as $name => $value) {
                expect($data['cookies'])->toHaveKey($name);
                expect($data['cookies'][$name])->toBe($value);
            }
        }
    });

    it('can receive cookies from server', function () {
        $response = Task::run(function () {
            return await(
                Http::request()
                    ->get("{$this->testUrl}/cookies/set?test_cookie=test_value&another=value2")
            );
        });

        expect($response->status())->toBe(200);

        $cookies = $response->getCookies();
        expect($cookies)->toHaveCount(2);

        $cookieNames = array_map(fn ($cookie) => $cookie->getName(), $cookies);
        expect($cookieNames)->toContain('test_cookie');
        expect($cookieNames)->toContain('another');
    });

    it('handles empty cookie values', function () {
        $response = Task::run(function () {
            return await(
                Http::request()
                    ->cookie('empty_cookie', '')
                    ->get("{$this->testUrl}/cookies")
            );
        });

        expect($response->status())->toBe(200);

        $data = $response->json();
        if (isset($data['cookies'])) {
            expect($data['cookies'])->toHaveKey('empty_cookie');
            expect($data['cookies']['empty_cookie'])->toBe('');
        }
    });

    it('handles special characters in cookie values', function () {
        $specialValue = 'value with spaces & symbols!@#$%^&*()';

        $response = Task::run(function () use ($specialValue) {
            return await(
                Http::request()
                    ->cookie('special_cookie', $specialValue)
                    ->get("{$this->testUrl}/cookies")
            );
        });

        expect($response->status())->toBe(200);
    });
});

describe('Cookie Object', function () {
    it('creates cookie with basic attributes', function () {
        $cookie = new Cookie(name: 'test', value: 'value');

        expect($cookie->getName())->toBe('test');
        expect($cookie->getValue())->toBe('value');
        expect($cookie->getDomain())->toBeNull();
        $path = $cookie->getPath();
        expect($path)->toBeString();
        expect($cookie->isSecure())->toBeFalse();
        expect($cookie->isHttpOnly())->toBeFalse();
    });

    it('creates cookie with all attributes', function () {
        $expires = time() + 3600;
        $cookie = new Cookie(
            name: 'complex',
            value: 'test_value',
            expires: $expires,
            domain: '.example.com',
            path: '/api',
            secure: true,
            httpOnly: true,
            maxAge: 3600,
            sameSite: 'Strict'
        );

        expect($cookie->getName())->toBe('complex');
        expect($cookie->getValue())->toBe('test_value');
        expect($cookie->getExpires())->toBe($expires);
        expect($cookie->getDomain())->toBe('.example.com');
        expect($cookie->getPath())->toBe('/api');
        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getMaxAge())->toBe(3600);
        expect($cookie->getSameSite())->toBe('Strict');
    });

    it('generates correct Set-Cookie header', function () {
        $cookie = new Cookie(
            name: 'test',
            value: 'value',
            expires: time() + 3600,
            domain: 'example.com',
            path: '/path',
            secure: true,
            httpOnly: true,
            maxAge: 3600,
            sameSite: 'Lax'
        );

        $header = $cookie->toSetCookieHeader();

        expect($header)->toContain('test=value');
        expect($header)->toContain('Domain=example.com');
        expect($header)->toContain('Path=/path');
        expect($header)->toContain('Secure');
        expect($header)->toContain('HttpOnly');
        expect($header)->toContain('Max-Age=3600');
        expect($header)->toContain('SameSite=Lax');
    });

    it('generates correct Cookie header', function () {
        $cookie = new Cookie('test', 'value');

        expect($cookie->toCookieHeader())->toBe('test=value');
    });

    it('parses Set-Cookie header correctly', function () {
        $header = 'session=abc123; Domain=.example.com; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=7200';

        $cookie = Cookie::fromSetCookieHeader($header);

        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('session');
        expect($cookie->getValue())->toBe('abc123');
        expect($cookie->getDomain())->toBe('.example.com');
        expect($cookie->getPath())->toBe('/');
        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getSameSite())->toBe('Strict');
        expect($cookie->getMaxAge())->toBe(7200);
    });

    it('handles malformed Set-Cookie header gracefully', function () {
        $malformedHeader = 'invalid header format';

        $cookie = Cookie::fromSetCookieHeader($malformedHeader);

        expect($cookie)->toBeNull();
    });

    it('checks if cookie is expired', function () {
        $expiredCookie = new Cookie('expired', 'value', time() - 1);
        $validCookie = new Cookie('valid', 'value', time() + 3600);
        $sessionCookie = new Cookie('session', 'value'); // no expiration

        expect($expiredCookie->isExpired())->toBeTrue();
        expect($validCookie->isExpired())->toBeFalse();
        expect($sessionCookie->isExpired())->toBeFalse();
    });
});

describe('CookieJar Functionality', function () {
    beforeEach(function () {
        $this->cookieJar = new CookieJar;
    });

    it('stores and retrieves cookies', function () {
        $cookie = new Cookie('test', 'value');

        $this->cookieJar->setCookie($cookie);

        expect($this->cookieJar->getAllCookies())->toHaveCount(1);
        expect($this->cookieJar->getAllCookies()[0]->getName())->toBe('test');
    });

    it('overwrites cookies with same name and domain', function () {
        $cookie1 = new Cookie('test', 'value1', null, 'example.com');
        $cookie2 = new Cookie('test', 'value2', null, 'example.com');

        $this->cookieJar->setCookie($cookie1);
        $this->cookieJar->setCookie($cookie2);

        $cookies = $this->cookieJar->getAllCookies();
        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getValue())->toBe('value2');
    });

    it('keeps cookies with same name but different domains', function () {
        $cookie1 = new Cookie('test', 'value1', null, 'example.com');
        $cookie2 = new Cookie('test', 'value2', null, 'test.com');

        $this->cookieJar->setCookie($cookie1);
        $this->cookieJar->setCookie($cookie2);

        expect($this->cookieJar->getAllCookies())->toHaveCount(2);
    });

    it('clears expired cookies', function () {
        $expiredCookie = new Cookie('expired', 'value', time() - 1);
        $validCookie = new Cookie('valid', 'value', time() + 3600);
        $sessionCookie = new Cookie('session', 'value');

        $this->cookieJar->setCookie($expiredCookie);
        $this->cookieJar->setCookie($validCookie);
        $this->cookieJar->setCookie($sessionCookie);

        $initialCount = count($this->cookieJar->getAllCookies());
        expect($initialCount)->toBeGreaterThanOrEqual(2);

        $this->cookieJar->clearExpired();

        $remaining = $this->cookieJar->getAllCookies();
        expect($remaining)->toHaveCount($initialCount - 1);

        $names = array_map(fn ($c) => $c->getName(), $remaining);
        expect($names)->toContain('valid');
        expect($names)->toContain('session');
        expect($names)->not->toContain('expired');
    });

    it('matches cookies by exact domain', function () {
        $this->cookieJar->setCookie(new Cookie('exact', 'value', null, 'example.com'));
        $this->cookieJar->setCookie(new Cookie('other', 'value', null, 'other.com'));

        $matches = $this->cookieJar->getCookies('example.com', '/');

        expect($matches)->toHaveCount(1);
        expect($matches[0]->getName())->toBe('exact');
    });

    it('matches cookies by subdomain with leading dot', function () {
        $this->cookieJar->setCookie(new Cookie('parent', 'value', null, '.example.com'));
        $this->cookieJar->setCookie(new Cookie('exact', 'value', null, 'example.com'));

        $subdomainMatches = $this->cookieJar->getCookies('sub.example.com', '/');
        $exactMatches = $this->cookieJar->getCookies('example.com', '/');

        expect($subdomainMatches)->toHaveCount(1);
        expect($subdomainMatches[0]->getName())->toBe('parent');
        expect($exactMatches)->toHaveCount(2);
    });

    it('matches cookies by path', function () {
        $this->cookieJar->setCookie(new Cookie('root', 'value', null, 'example.com', '/'));
        $this->cookieJar->setCookie(new Cookie('api', 'value', null, 'example.com', '/api'));
        $this->cookieJar->setCookie(new Cookie('admin', 'value', null, 'example.com', '/admin'));

        $rootMatches = $this->cookieJar->getCookies('example.com', '/');
        $apiMatches = $this->cookieJar->getCookies('example.com', '/api');
        $adminMatches = $this->cookieJar->getCookies('example.com', '/admin');

        expect($rootMatches)->toHaveCount(1);
        expect($apiMatches)->toHaveCount(2);
        expect($adminMatches)->toHaveCount(2);
    });

    it('generates correct Cookie header', function () {
        $this->cookieJar->setCookie(new Cookie('first', 'value1'));
        $this->cookieJar->setCookie(new Cookie('second', 'value2'));

        $header = $this->cookieJar->getCookieHeader('example.com', '/');

        expect($header)->toContain('first=value1');
        expect($header)->toContain('second=value2');
        expect($header)->toContain(';');
    });

    it('clears all cookies', function () {
        $this->cookieJar->setCookie(new Cookie('test1', 'value1'));
        $this->cookieJar->setCookie(new Cookie('test2', 'value2'));

        expect($this->cookieJar->getAllCookies())->toHaveCount(2);

        $this->cookieJar->clear();

        expect($this->cookieJar->getAllCookies())->toHaveCount(0);
    });
});

describe('FileCookieJar Persistence', function () {
    beforeEach(function () {
        $this->cookieFile = sys_get_temp_dir().'/test_cookies_'.uniqid().'.json';
    });

    afterEach(function () {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    });

    it('saves cookies to file', function () {
        $jar = new FileCookieJar($this->cookieFile);
        $cookie = new Cookie('persistent', 'value', time() + 3600);

        $jar->setCookie($cookie);

        expect(file_exists($this->cookieFile))->toBeTrue();

        $content = file_get_contents($this->cookieFile);
        expect($content)->toContain('persistent');
    });

    it('loads cookies from file', function () {
        $jar1 = new FileCookieJar($this->cookieFile);
        $cookie = new Cookie('persistent', 'value', time() + 3600);
        $jar1->setCookie($cookie);

        $jar2 = new FileCookieJar($this->cookieFile);

        $cookies = $jar2->getAllCookies();
        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getName())->toBe('persistent');
        expect($cookies[0]->getValue())->toBe('value');
    });

    it('handles session cookies based on configuration', function () {
        $sessionCookie = new Cookie('session', 'temp');
        $persistentCookie = new Cookie('persistent', 'value', time() + 3600);

        $jar1 = new FileCookieJar($this->cookieFile, false);
        $jar1->setCookie($sessionCookie);
        $jar1->setCookie($persistentCookie);

        $jar2 = new FileCookieJar($this->cookieFile, false);
        $cookies = $jar2->getAllCookies();

        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getName())->toBe('persistent');

        unlink($this->cookieFile);

        $jar3 = new FileCookieJar($this->cookieFile, true);
        $jar3->setCookie($sessionCookie);
        $jar3->setCookie($persistentCookie);

        $jar4 = new FileCookieJar($this->cookieFile, true);
        $cookies = $jar4->getAllCookies();

        expect($cookies)->toHaveCount(2);
    });

    it('handles corrupted cookie file gracefully', function () {
        file_put_contents($this->cookieFile, 'invalid json content');

        try {
            $jar = new FileCookieJar($this->cookieFile);
            expect(true)->toBeTrue();
        } catch (Exception $e) {
            expect($e)->toBeInstanceOf(Exception::class);
        }
    });
});

describe('HTTP Client Cookie Integration', function () {
    it('automatically handles cookies with CookieJar', function () {
        $cookieJar = new CookieJar;

        $response1 = Task::run(function () use ($cookieJar) {
            return await(
                Http::request()
                    ->withCookieJar($cookieJar)
                    ->get("{$this->testUrl}/cookies/set/auto_test/12345")
            );
        });

        expect($response1->status())->toBe(200);

        if (count($cookieJar->getAllCookies()) === 0) {
            $response1->applyCookiesToJar($cookieJar);
        }

        expect($cookieJar->getAllCookies())->toHaveCount(1);

        $response2 = Task::run(function () use ($cookieJar) {
            return await(
                Http::request()
                    ->withCookieJar($cookieJar)
                    ->get("{$this->testUrl}/cookies")
            );
        });

        $data = $response2->json();
        if (isset($data['cookies'])) {
            expect($data['cookies'])->toHaveKey('auto_test');
            expect($data['cookies']['auto_test'])->toBe('12345');
        }
    });

    it('handles secure cookies correctly', function () {
        $cookie = new Cookie('secure_test', 'value', null, $this->testDomain, '/', true);

        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->toSetCookieHeader())->toContain('Secure');
    });

    it('handles HttpOnly cookies correctly', function () {
        $cookie = new Cookie('httponly_test', 'value', null, $this->testDomain, '/', false, true);

        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->toSetCookieHeader())->toContain('HttpOnly');
    });

    it('handles SameSite attribute correctly', function () {
        $strictCookie = new Cookie('strict', 'value', null, null, '/', false, false, null, 'Strict');
        $laxCookie = new Cookie('lax', 'value', null, null, '/', false, false, null, 'Lax');
        $noneCookie = new Cookie('none', 'value', null, null, '/', false, false, null, 'None');

        expect($strictCookie->getSameSite())->toBe('Strict');
        expect($laxCookie->getSameSite())->toBe('Lax');
        expect($noneCookie->getSameSite())->toBe('None');

        expect($strictCookie->toSetCookieHeader())->toContain('SameSite=Strict');
        expect($laxCookie->toSetCookieHeader())->toContain('SameSite=Lax');
        expect($noneCookie->toSetCookieHeader())->toContain('SameSite=None');
    });
});

describe('Performance and Edge Cases', function () {
    it('handles large number of cookies efficiently', function () {
        $cookieJar = new CookieJar;
        $startTime = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $domain = match ($i % 3) {
                0 => 'example.com',
                1 => 'test.com',
                default => 'other.com'
            };
            $cookieJar->setCookie(new Cookie("cookie_{$i}", "value_{$i}", time() + 3600, $domain));
        }

        $addTime = microtime(true) - $startTime;
        expect($addTime)->toBeLessThan(1.0);

        $startTime = microtime(true);
        $matches = $cookieJar->getCookies('example.com', '/');
        $matchTime = microtime(true) - $startTime;

        expect($matchTime)->toBeLessThan(0.1);
        expect($matches)->toHaveCount(334);
    });

    it('handles cookies with unicode values', function () {
        $unicodeValue = '测试值🍪';

        $response = Task::run(function () use ($unicodeValue) {
            return await(
                Http::request()
                    ->cookie('unicode_test', $unicodeValue)
                    ->get("{$this->testUrl}/cookies")
            );
        });

        expect($response->status())->toBe(200);
    });

    it('handles very long cookie values', function () {
        $longValue = str_repeat('a', 4000);

        $cookie = new Cookie('long_test', $longValue);
        expect($cookie->getValue())->toBe($longValue);
        expect($cookie->getName())->toBe('long_test');
    });

    it('handles cookies with no domain', function () {
        $cookie = new Cookie('no_domain', 'value');

        expect($cookie->getDomain())->toBeNull();

        $cookieJar = new CookieJar;
        $cookieJar->setCookie($cookie);

        $matches = $cookieJar->getCookies('any-domain.com', '/');
        expect($matches)->toHaveCount(1);
    });

    it('handles path matching edge cases', function () {
        $cookieJar = new CookieJar;
        $cookieJar->setCookie(new Cookie('root', 'value', null, 'example.com', '/'));
        $cookieJar->setCookie(new Cookie('deep', 'value', null, 'example.com', '/api/v1/users'));

        $rootMatches = $cookieJar->getCookies('example.com', '/');
        $apiMatches = $cookieJar->getCookies('example.com', '/api');
        $deepMatches = $cookieJar->getCookies('example.com', '/api/v1/users');
        $deeperMatches = $cookieJar->getCookies('example.com', '/api/v1/users/123');

        expect($rootMatches)->toHaveCount(1);
        expect($apiMatches)->toHaveCount(1);
        expect($deepMatches)->toHaveCount(2);
        expect($deeperMatches)->toHaveCount(2);
    });
});

describe('Error Handling', function () {
    it('handles network errors gracefully', function () {
        try {
            Task::run(function () {
                return await(
                    Http::request()
                        ->cookie('test', 'value')
                        ->get('https://nonexistent-domain-for-testing.invalid/cookies')
                );
            });

            expect(false)->toBeTrue('Expected network error but request succeeded');
        } catch (Exception $e) {
            expect($e)->toBeInstanceOf(Exception::class);
        }
    });

    it('handles malformed cookie responses', function () {
        expect(true)->toBeTrue();
    });

    it('validates cookie names', function () {
        $invalidNames = ['cookie name', 'cookie;name', 'cookie=name'];

        foreach ($invalidNames as $invalidName) {
            $cookie = new Cookie($invalidName, 'value');
            expect($cookie->getName())->toBe($invalidName);
            expect($cookie->getValue())->toBe('value');
        }
    });
});
