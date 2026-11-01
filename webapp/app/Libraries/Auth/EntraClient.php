<?php

namespace App\Libraries\Auth;

use Config\Entra;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * OpenID Connect against Microsoft Entra ID: authorization code flow with PKCE, state and nonce.
 * The ID token is verified completely (signature via the tenant's JWKS, issuer, audience, tenant,
 * expiry, nonce) before the user's role is derived from the group claim.
 */
class EntraClient
{
    private const AUTHORITY = 'https://login.microsoftonline.com/';
    private const LEEWAY    = 120;

    /** @var callable(string, array): array HTTP POST (url, form) -> decoded JSON */
    private $httpPost;

    /** @var callable(string): array HTTP GET (url) -> decoded JSON */
    private $httpGet;

    public function __construct(private readonly Entra $config, ?callable $httpPost = null, ?callable $httpGet = null)
    {
        $this->httpPost = $httpPost ?? [$this, 'defaultPost'];
        $this->httpGet  = $httpGet ?? [$this, 'defaultGet'];
    }

    /**
     * Start a sign-in. Returns the Entra URL and the values to keep in the session.
     *
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function authorizationRequest(string $redirectUri): array
    {
        $state    = self::random();
        $nonce    = self::random();
        $verifier = self::random(48);
        $query    = http_build_query([
            'client_id'             => $this->config->clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $redirectUri,
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email',
            'prompt'                => 'select_account', // always let the user choose the account
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $this->authority() . '/oauth2/v2.0/authorize?' . $query, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];
    }

    /** Exchange the authorization code for tokens and return the verified ID token claims. */
    public function redeemCode(string $code, string $redirectUri, string $verifier, string $nonce): stdClass
    {
        $response = ($this->httpPost)($this->authority() . '/oauth2/v2.0/token', [
            'client_id'     => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $verifier,
            'scope'         => 'openid profile email',
        ]);
        if (empty($response['id_token'])) {
            throw new RuntimeException('Token endpoint: ' . ($response['error_description'] ?? $response['error'] ?? 'no id_token'));
        }

        return $this->verifyIdToken($response['id_token'], $nonce);
    }

    /** Verify signature and claims of an ID token. */
    public function verifyIdToken(string $idToken, string $nonce): stdClass
    {
        JWT::$leeway = self::LEEWAY;
        $claims      = JWT::decode($idToken, JWK::parseKeySet($this->jwks(), 'RS256'));

        $tenant = $this->config->tenantId;
        if (($claims->iss ?? '') !== self::AUTHORITY . $tenant . '/v2.0') {
            throw new RuntimeException('Invalid issuer');
        }
        if (($claims->aud ?? '') !== $this->config->clientId) {
            throw new RuntimeException('Invalid audience');
        }
        if (($claims->tid ?? '') !== $tenant) {
            throw new RuntimeException('Invalid tenant');
        }
        if (! hash_equals($nonce, (string) ($claims->nonce ?? ''))) {
            throw new RuntimeException('Invalid nonce');
        }
        if (empty($claims->oid)) {
            throw new RuntimeException('Missing object id');
        }

        return $claims;
    }

    /**
     * Role from the group claim: "rw" (read/write group), "ro" (read-only group) or null (no access).
     * Group overage (_claim_names instead of groups) is treated as no access and must be avoided in
     * the app registration ("groups assigned to the application").
     */
    public function role(stdClass $claims): ?string
    {
        $groups = array_map('strval', (array) ($claims->groups ?? []));
        if ($this->config->groupRw !== '' && in_array($this->config->groupRw, $groups, true)) {
            return 'rw';
        }
        if ($this->config->groupRo !== '' && in_array($this->config->groupRo, $groups, true)) {
            return 'ro';
        }

        return null;
    }

    /** Why no role was found – for the log (contains no secrets, only group object ids). */
    public function groupDiagnosis(stdClass $claims): string
    {
        if (isset($claims->_claim_names->groups) || isset($claims->hasgroups)) {
            return 'group overage: too many groups for the token – configure "groups assigned to the application" in the token configuration';
        }
        if (! isset($claims->groups)) {
            return 'no groups claim in the ID token – add a groups claim (security groups) in the token configuration; claims present: '
                . implode(',', array_keys(get_object_vars($claims)));
        }
        $groups = array_map('strval', (array) $claims->groups);

        return sprintf('groups claim has %d ids [%s]; configured rw=%s ro=%s', count($groups), implode(',', $groups),
            $this->config->groupRw ?: '-', $this->config->groupRo ?: '-');
    }

    public function logoutUrl(string $postLogoutRedirectUri): string
    {
        return $this->authority() . '/oauth2/v2.0/logout?' . http_build_query(['post_logout_redirect_uri' => $postLogoutRedirectUri]);
    }

    /** Signing keys of the tenant, cached for 12 hours. */
    private function jwks(): array
    {
        $cacheKey = 'entra_jwks_' . md5($this->config->tenantId);
        $cache    = function_exists('cache') ? cache() : null;
        if ($cache !== null && is_array($keys = $cache->get($cacheKey))) {
            return $keys;
        }
        $keys = ($this->httpGet)($this->authority() . '/discovery/v2.0/keys');
        if (empty($keys['keys'])) {
            throw new RuntimeException('JWKS: no keys');
        }
        $cache?->save($cacheKey, $keys, 43200);

        return $keys;
    }

    private function authority(): string
    {
        return self::AUTHORITY . rawurlencode($this->config->tenantId);
    }

    private function defaultPost(string $url, array $form): array
    {
        return $this->request('POST', $url, ['form_params' => $form]);
    }

    private function defaultGet(string $url): array
    {
        return $this->request('GET', $url, []);
    }

    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = service('curlrequest', ['timeout' => 10, 'http_errors' => false])->request($method, $url, $options);
        } catch (Throwable $e) {
            throw new RuntimeException('Entra not reachable: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode($response->getBody(), true);
        if (! is_array($data)) {
            throw new RuntimeException("Entra: invalid response ({$response->getStatusCode()})");
        }

        return $data;
    }

    public static function random(int $bytes = 32): string
    {
        return self::base64url(random_bytes($bytes));
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
