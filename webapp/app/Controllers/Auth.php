<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Throwable;

/**
 * Sign-in via Microsoft Entra ID. Ravenkeep stores no passwords; MFA is enforced by Entra.
 */
class Auth extends BaseController
{
    protected $helpers = ['url'];

    public function login(): RedirectResponse|string
    {
        $config = config('Entra');
        if (! $config->isConfigured()) {
            return $this->denied(lang('Auth.notConfigured'), 503);
        }
        $request = service('entraClient')->authorizationRequest(site_url($config->redirectPath));
        session()->set('oidc', [
            'state' => $request['state'], 'nonce' => $request['nonce'], 'verifier' => $request['verifier'],
            'return' => $this->safeReturn((string) $this->request->getGet('return')), 'created' => time(),
        ]);

        return redirect()->to($request['url']);
    }

    public function callback(): RedirectResponse|string
    {
        $config = config('Entra');
        $oidc   = session('oidc');
        session()->remove('oidc');

        if ($this->request->getGet('error')) {
            log_message('warning', 'Entra sign-in error: ' . $this->request->getGet('error') . ' ' . $this->request->getGet('error_description'));

            return $this->denied(lang('Auth.failed'));
        }
        if (! is_array($oidc) || time() - $oidc['created'] > 600
            || ! hash_equals($oidc['state'], (string) $this->request->getGet('state'))) {
            return $this->denied(lang('Auth.expired'));
        }

        $client = service('entraClient');

        try {
            $tokens = $client->redeemCode((string) $this->request->getGet('code'), site_url($config->redirectPath), $oidc['verifier'], $oidc['nonce']);
            $claims = $tokens['claims'];
            $role   = $client->roleFor($claims, $tokens['access_token']);
        } catch (Throwable $e) {
            log_message('error', 'Entra sign-in rejected: ' . $e->getMessage());

            return $this->denied(lang('Auth.failed'));
        }

        $user = [
            'oid'      => (string) $claims->oid,
            'name'     => (string) ($claims->name ?? $claims->preferred_username ?? $claims->oid),
            'username' => (string) ($claims->preferred_username ?? ''),
            'role'     => $role,
            'login_at' => time(),
        ];
        if ($user['role'] === null) {
            log_message('warning', "Entra sign-in without Ravenkeep group: {$user['username']} ({$user['oid']}) – " . $client->groupDiagnosis($claims));

            return $this->denied(lang('Auth.noGroup', [$user['username'] ?: $user['name']]), 403);
        }

        session()->regenerate(true);
        session()->set('user', $user);
        log_message('info', "Sign-in {$user['username']} ({$user['oid']}) role {$user['role']}");

        return redirect()->to(site_url(ltrim($oidc['return'], '/')));
    }

    public function logout(): RedirectResponse
    {
        $configured = config('Entra')->isConfigured();
        session()->destroy();

        return redirect()->to($configured ? service('entraClient')->logoutUrl(site_url('auth/signed-out')) : site_url('auth/signed-out'));
    }

    public function signedOut(): string
    {
        return view('auth/message', ['title' => lang('Auth.signedOutTitle'), 'message' => lang('Auth.signedOut'), 'login' => true]);
    }

    private function denied(string $message, int $status = 401): string
    {
        $this->response->setStatusCode($status);

        return view('auth/message', ['title' => lang('Auth.deniedTitle'), 'message' => $message, 'login' => true]);
    }

    /** Only paths within the application. */
    private function safeReturn(string $path): string
    {
        return preg_match('#^/(?!/)[^\s\\\\]*$#', $path) && ! str_starts_with($path, '/auth/') ? $path : '/';
    }
}
