<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

class RetryHelperHandler
{
    public static function getRetryLogic($request, $url, $options = [])
    {
        if (isset($options['headers'])) {
            $request->headers($options['headers']);
        }

        if (isset($options['method'])) {
            $method = strtoupper($options['method']);
        } else {
            $method = 'GET';
        }

        if (isset($options['body'])) {
            $request->body($options['body']);
        }

        if (isset($options['json'])) {
            $request->json($options['json']);
        }

        if (isset($options['form'])) {
            $request->form($options['form']);
        }

        if (isset($options['timeout'])) {
            $request->timeout($options['timeout']);
        }

        if (isset($options['user_agent'])) {
            $request->userAgent($options['user_agent']);
        }

        if (isset($options['verify_ssl'])) {
            $request->verifySSL($options['verify_ssl']);
        }

        if (isset($options['auth'])) {
            if (isset($options['auth']['bearer'])) {
                $request->bearerToken($options['auth']['bearer']);
            } elseif (isset($options['auth']['basic'])) {
                $request->basicAuth($options['auth']['basic']['username'], $options['auth']['basic']['password']);
            }
        }

        return $request->send($method, $url);
    }
}
