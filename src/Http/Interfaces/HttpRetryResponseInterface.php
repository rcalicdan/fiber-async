<?php

namespace Rcalicdan\FiberAsync\Http\Interfaces;

/**
 * Interface for HTTP request objects that can be configured with retry logic.
 */
interface HttpRetryResponseInterface
{
    /**
     * Set request headers.
     * 
     * @param array<string, string> $headers
     * @return mixed
     */
    public function headers(array $headers): mixed;

    /**
     * Set request body.
     * 
     * @param string $body
     * @return mixed
     */
    public function body(string $body): mixed;

    /**
     * Set JSON data for request.
     * 
     * @param array<string, mixed> $json
     * @return mixed
     */
    public function json(array $json): mixed;

    /**
     * Set form data for request.
     * 
     * @param array<string, mixed> $form
     * @return mixed
     */
    public function form(array $form): mixed;

    /**
     * Set request timeout.
     * 
     * @param int $timeout
     * @return mixed
     */
    public function timeout(int $timeout): mixed;

    /**
     * Set user agent string.
     * 
     * @param string $userAgent
     * @return mixed
     */
    public function userAgent(string $userAgent): mixed;

    /**
     * Set SSL verification.
     * 
     * @param bool $verify
     * @return mixed
     */
    public function verifySSL(bool $verify): mixed;

    /**
     * Set bearer token authentication.
     * 
     * @param string $token
     * @return mixed
     */
    public function bearerToken(string $token): mixed;

    /**
     * Set basic authentication.
     * 
     * @param string $username
     * @param string $password
     * @return mixed
     */
    public function basicAuth(string $username, string $password): mixed;

    /**
     * Send the HTTP request.
     * 
     * @param string $method
     * @param string $url
     * @return mixed
     */
    public function send(string $method, string $url): mixed;
}