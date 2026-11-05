<?php

namespace App\Libraries\Mailrunner;

use App\Libraries\Nodes\NodeSettings;
use App\Libraries\Secrets;
use App\Libraries\Rules\RuleRunner;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * SMTP logins on the MailRunner node (T-21, E-024). Ravenkeep generates the password, hands it to
 * the node (which never returns it), shows it once and keeps it encrypted (Secrets), so the same
 * login can be set up on a further node. The node reports the login of
 * a message as auth.username / credential_id; Ravenkeep keeps credential_id, user name, device and tags. Every change goes to the node first, so the
 * local list never claims a login the node does not have.
 */
class SmtpCredentials
{
    private readonly Secrets $secrets;

    public function __construct(private readonly BaseConnection $db, private readonly MailrunnerClient $client, ?Secrets $secrets = null)
    {
        // without a key no login is created: the password could not be read again
        $this->secrets = $secrets ?? Secrets::fromConfig();
    }

    /** Password of a login (for setting it up on a further node). */
    public function password(int $id): string
    {
        $row = $this->find($id);
        if ($row['password_enc'] === null) {
            throw new InvalidArgumentException('no_password');
        }

        return $this->secrets->decrypt($row['password_enc']);
    }

    /**
     * @param list<string> $networks
     *
     * @return array{0: int, 1: string, 2: string} [id, password, user name]
     */
    public function create(string $localPart, string $domain, ?string $note, ?int $deviceId, bool $plaintextPort25 = false, array $networks = []): array
    {
        $username = (new NodeSettings($this->db))->username($localPart, $domain);
        if ($this->db->table('smtp_identities')->where('username', $username)->countAllResults() > 0) {
            throw new InvalidArgumentException('exists');
        }
        if ($deviceId !== null && $this->db->table('devices')->where('id', $deviceId)->countAllResults() === 0) {
            throw new InvalidArgumentException('device');
        }
        $networks = self::networks($networks);
        $password   = NodeSettings::generatePassword();
        $credential = $this->call(fn () => $this->client->createCredential($username, $password, $note ?? $username, $plaintextPort25, $networks));
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('smtp_identities')->insert([
            'credential_id' => (string) $credential['credential_id'], 'device_id' => $deviceId, 'username' => $username, 'password_hash' => null,
            'password_enc' => $this->secrets->encrypt($password),
            'note' => $note, 'plaintext_port25' => $plaintextPort25 ? 1 : 0, 'allowed_networks' => $networks === [] ? null : implode(', ', $networks),
            'password_set_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID(); // before any other query
        (new RuleRunner($this->db))->bumpVersion();

        return [$id, $password, $username];
    }

    /** Create a login from before MailRunner on the node (same user name). Returns the new password. */
    public function register(int $id): string
    {
        $row = $this->find($id);
        if ($row['credential_id'] !== null) {
            throw new InvalidArgumentException('registered');
        }
        $networks = self::networks(explode(',', (string) $row['allowed_networks']));
        $password   = NodeSettings::generatePassword();
        $credential = $this->call(fn () => $this->client->createCredential($row['username'], $password, $row['note'] ?? $row['username'], (bool) $row['plaintext_port25'], $networks));
        if (! $row['enabled']) {
            $this->client->updateCredential((string) $credential['credential_id'], ['enabled' => false]);
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('smtp_identities')->where('id', $id)->update(['credential_id' => (string) $credential['credential_id'], 'password_hash' => null,
            'password_enc' => $this->secrets->encrypt($password), 'password_set_at' => $now, 'updated_at' => $now]);

        return $password;
    }

    /** New password; the old one stays valid for $graceSeconds (to switch the device over). */
    public function rotate(int $id, int $graceSeconds): string
    {
        $row      = $this->registered($id);
        $password = NodeSettings::generatePassword();
        $this->call(fn () => $this->client->rotateCredential($row['credential_id'], $password, $graceSeconds));
        $now      = gmdate('Y-m-d H:i:s');
        $this->db->table('smtp_identities')->where('id', $id)->update(['password_enc' => $this->secrets->encrypt($password), 'password_set_at' => $now, 'updated_at' => $now]);

        return $password;
    }

    public function toggle(int $id): void
    {
        $row     = $this->find($id);
        $enabled = ! $row['enabled'];
        if ($row['credential_id'] !== null) {
            $this->call(fn () => $this->client->updateCredential($row['credential_id'], ['enabled' => $enabled]));
        }
        $this->db->table('smtp_identities')->where('id', $id)->update(['enabled' => $enabled ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** Port 25 without TLS (old devices) and allowed networks. @param list<string> $networks */
    public function updateOptions(int $id, bool $plaintextPort25, array $networks): void
    {
        $row      = $this->find($id);
        $networks = self::networks($networks);
        if ($row['credential_id'] !== null) {
            $this->call(fn () => $this->client->updateCredential($row['credential_id'], ['plaintext_port25' => $plaintextPort25, 'allowed_networks' => $networks]));
        }
        $this->db->table('smtp_identities')->where('id', $id)->update(['plaintext_port25' => $plaintextPort25 ? 1 : 0,
            'allowed_networks' => $networks === [] ? null : implode(', ', $networks), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row['credential_id'] !== null) {
            $this->call(fn () => $this->client->deleteCredential($row['credential_id']));
        }
        $this->db->table('smtp_identities')->where('id', $id)->delete();
        (new RuleRunner($this->db))->bumpVersion();
    }

    /** @return array<string, string> credential_id => user name (for the collector) */
    public static function map(BaseConnection $db): array
    {
        return array_column($db->table('smtp_identities')->select('credential_id, username')->where('credential_id IS NOT NULL')->get()->getResultArray(),
            'username', 'credential_id');
    }

    /** @return list<string> */
    public static function networks(array $networks): array
    {
        $out = [];
        foreach ($networks as $network) {
            $network = trim((string) $network);
            if ($network !== '') {
                $out[] = NodeSettings::normalizeNetwork($network);
            }
        }

        return array_values(array_unique($out));
    }

    /** Run an API call; API errors become InvalidArgumentException with a lower-case code. */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (MailrunnerApiError $e) {
            throw new InvalidArgumentException('mr_' . strtolower($e->apiCode), 0, $e);
        }
    }

    private function find(int $id): array
    {
        return $this->db->table('smtp_identities')->where('id', $id)->get()->getRowArray() ?? throw new InvalidArgumentException('identity');
    }

    private function registered(int $id): array
    {
        $row = $this->find($id);
        if ($row['credential_id'] === null) {
            throw new InvalidArgumentException('not_registered');
        }

        return $row;
    }
}
