<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Access to the MailRunner receive node (decision E-014).
 *
 * URL and token are kept outside the repository (default /etc/ravenkeep/mailrunner.env,
 * root:www-data 0640):
 *   MAILRUNNER_URL=https://<receive node>
 *   MAILRUNNER_TOKEN=<token_id>.<secret>
 */
class Mailrunner extends BaseConfig
{
    public string $credentialsFile = '/etc/ravenkeep/mailrunner.env';

    public string $url   = '';
    public string $token = '';

    /** Timeout of one API request in seconds. */
    public int $timeout = 30;

    public function __construct()
    {
        parent::__construct();
        $file = getenv('MAILRUNNER_CREDENTIALS_FILE') ?: $this->credentialsFile;
        if (! is_readable($file)) {
            return;
        }
        $map = ['MAILRUNNER_URL' => 'url', 'MAILRUNNER_TOKEN' => 'token'];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            if (isset($map[$key])) {
                $this->{$map[$key]} = trim(explode(' ', trim($value), 2)[0]);
            }
        }
        $this->url = rtrim($this->url, '/');
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }
}
