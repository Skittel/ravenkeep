<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Every page except /auth/* requires an Entra sign-in. Read-only users may only read:
 * every request that changes something (POST) requires the read/write role.
 */
class RequireLogin implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user   = session('user');
        $config = config('Entra');

        if (! is_array($user) || time() - (int) ($user['login_at'] ?? 0) > $config->maxSessionAge) {
            session()->remove('user');
            if ($request->isAJAX()) {
                return service('response')->setStatusCode(401)->setBody('Not signed in');
            }
            $return = '/' . ltrim($request->getUri()->getPath(), '/') . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');

            return redirect()->to(site_url('auth/login') . '?return=' . rawurlencode($return));
        }

        if (strtoupper($request->getMethod()) !== 'GET' && ($user['role'] ?? '') !== 'rw') {
            return service('response')->setStatusCode(403)->setBody(lang('Auth.readOnly'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
