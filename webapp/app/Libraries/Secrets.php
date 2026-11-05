<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Symmetric encryption of secrets Ravenkeep must be able to read again (e.g. the password of an
 * SMTP login, to set it up on a further MailRunner node). libsodium secretbox (XSalsa20-Poly1305)
 * with a random nonce per value. The key (32 bytes, base64) is kept outside the repository and the
 * database, default /etc/ravenkeep/secret.key (root:www-data 0640). Losing the key only means the
 * passwords have to be changed; it belongs in the backup of /etc/ravenkeep.
 */
final class Secrets
{
    private const PREFIX = 'v1:';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Secret key must be 32 bytes');
        }
    }

    public static function fromConfig(): self
    {
        $file = getenv('RAVENKEEP_SECRET_KEY_FILE') ?: config('Ravenkeep')->secretKeyFile;
        if (! is_readable($file)) {
            throw new RuntimeException("Secret key file {$file} is missing or not readable");
        }
        $key = base64_decode(trim((string) file_get_contents($file)), true);
        if ($key === false) {
            throw new RuntimeException("Secret key file {$file} is not base64");
        }

        return new self($key);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $raw = str_starts_with($encrypted, self::PREFIX) ? base64_decode(substr($encrypted, strlen(self::PREFIX)), true) : false;
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted value');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($plain === false) {
            throw new RuntimeException('Encrypted value cannot be decrypted (wrong key?)');
        }

        return $plain;
    }
}
