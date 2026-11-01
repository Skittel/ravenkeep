<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Microsoft Entra ID sign-in (OpenID Connect). Credentials are read from a server-local file
 * outside the repository (default /etc/ravenkeep/entra.env, readable by the web server only):
 *
 *   ENTRA_TENANT_ID=...      ENTRA_CLIENT_ID=...      ENTRA_CLIENT_SECRET=...
 *   ENTRA_GROUP_RW=<object id of the read/write group>   (or ENTRA_ALLOWED_GROUP_RW_ID)
 *   ENTRA_GROUP_RO=<object id of the read-only group>    (or ENTRA_ALLOWED_GROUP_RO_ID)
 */
class Entra extends BaseConfig
{
    public string $credentialsFile = '/etc/ravenkeep/entra.env';

    public string $tenantId     = '';
    public string $clientId     = '';
    public string $clientSecret = '';
    public string $groupRw      = '';
    public string $groupRo      = '';

    /** Redirect URI registered in the app registration. */
    public string $redirectPath = 'auth/callback';

    /** Absolute session lifetime in seconds (sign-in again after this, even when active). */
    public int $maxSessionAge = 43200;

    public function __construct()
    {
        parent::__construct();
        $file = getenv('ENTRA_CREDENTIALS_FILE') ?: $this->credentialsFile;
        if (! is_readable($file)) {
            return;
        }
        $map = ['ENTRA_TENANT_ID' => 'tenantId', 'ENTRA_CLIENT_ID' => 'clientId', 'ENTRA_CLIENT_SECRET' => 'clientSecret',
            'ENTRA_GROUP_RW' => 'groupRw', 'ENTRA_GROUP_RO' => 'groupRo',
            'ENTRA_ALLOWED_GROUP_RW_ID' => 'groupRw', 'ENTRA_ALLOWED_GROUP_RO_ID' => 'groupRo']; // both spellings accepted
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            if (isset($map[$key])) {
                $this->{$map[$key]} = trim(explode(' ', trim($value), 2)[0]);
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->tenantId !== '' && $this->clientId !== '' && $this->clientSecret !== ''
            && ($this->groupRw !== '' || $this->groupRo !== '');
    }
}
