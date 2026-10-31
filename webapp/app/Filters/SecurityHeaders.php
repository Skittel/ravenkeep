<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Strict security headers for every page. Responses that set their own
 * Content-Security-Policy (e.g. the sandboxed HTML mail view) keep it.
 */
class SecurityHeaders implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! $response->hasHeader('Content-Security-Policy')) {
            $response->setHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "img-src 'self' data:",
                "frame-src 'self'",
                "base-uri 'none'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]));
        }
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->setHeader('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
