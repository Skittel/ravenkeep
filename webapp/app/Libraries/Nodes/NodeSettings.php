<?php

namespace App\Libraries\Nodes;

use App\Libraries\Senders\SenderRules;
use App\Libraries\Inventory\Inventory;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagTypes;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * SMTP identities, local domains and admin networks: validation, storage and rendering of the
 * node config files. Each change must be followed by NodeConfigSync::syncAll().
 */
class NodeSettings
{
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    private const PASSWORD_LENGTH   = 24;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    // ------------------------------------------------------------------ SMTP identities

    /**
     * Create an identity with a generated password. Returns [id, plain password] – the password
     * is shown once and only its bcrypt hash is stored.
     *
     * @return array{0: int, 1: string}
     */
    public function createIdentity(string $localPart, string $domain, ?string $note, ?int $deviceId = null): array
    {
        $username = $this->validUsername($localPart, $domain);
        if ($this->db->table('smtp_identities')->where('username', $username)->countAllResults() > 0) {
            throw new InvalidArgumentException('exists');
        }
        $password = self::generatePassword();
        $now      = gmdate('Y-m-d H:i:s');
        $this->db->table('smtp_identities')->insert([
            'device_id' => $deviceId, 'username' => $username, 'password_hash' => self::hash($password), 'note' => $note,
            'password_set_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [(int) $this->db->insertID(), $password];
    }

    /**
     * SMTP login for a device: the user name is the device path (customer[.location].device) at the
     * chosen local domain. A device has at most one login.
     *
     * @return array{0: int, 1: string, 2: string} [id, plain password, user name]
     */
    public function createIdentityForDevice(int $deviceId, string $domain, ?string $note): array
    {
        $device = $this->db->table('devices')->where('id', $deviceId)->get()->getRowArray();
        if ($device === null) {
            throw new InvalidArgumentException('device');
        }
        if ($this->db->table('smtp_identities')->where('device_id', $deviceId)->countAllResults() > 0) {
            throw new InvalidArgumentException('device_has_login');
        }
        [$id, $password] = $this->createIdentity($device['path'], $domain, $note, $deviceId);

        return [$id, $password, "{$device['path']}@" . strtolower(trim($domain))];
    }

    /**
     * Device and fixed tags of a login (E-024). The device supplies customer, location and device
     * tags; the login adds its own (e.g. source:ipmi). Reserved types and unknown tag types are refused.
     *
     * @param list<string> $tags
     */
    public function setIdentity(int $id, ?int $deviceId, array $tags, ?string $note): void
    {
        if ($this->db->table('smtp_identities')->where('id', $id)->countAllResults() === 0) {
            throw new InvalidArgumentException('identity');
        }
        if ($deviceId !== null && $this->db->table('devices')->where('id', $deviceId)->countAllResults() === 0) {
            throw new InvalidArgumentException('device');
        }
        $names = Inventory::validTags($tags);
        (new TagTypes($this->db))->assertKnown($names);
        $runner = new RuleRunner($this->db);
        $this->db->transStart();
        $this->db->table('smtp_identities')->where('id', $id)->update(['device_id' => $deviceId, 'note' => $note, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->table('smtp_identity_tags')->where('identity_id', $id)->delete();
        foreach ($names as $name) {
            $this->db->table('smtp_identity_tags')->insert(['identity_id' => $id, 'tag_id' => $runner->tagId($name)]);
        }
        $this->db->transComplete();
        $runner->bumpVersion();
    }

    /** Set a new generated password. Returns the plain password (shown once). */
    public function resetPassword(int $id): string
    {
        $password = self::generatePassword();
        $now      = gmdate('Y-m-d H:i:s');
        $this->db->table('smtp_identities')->where('id', $id)
            ->update(['password_hash' => self::hash($password), 'password_set_at' => $now, 'updated_at' => $now]);

        return $password;
    }

    public function toggleIdentity(int $id): void
    {
        $this->db->query('UPDATE smtp_identities SET enabled = 1 - enabled, updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }

    public function deleteIdentity(int $id): void
    {
        $this->db->table('smtp_identities')->where('id', $id)->delete();
    }

    public static function generatePassword(): string
    {
        $max      = strlen(self::PASSWORD_ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, $max)];
        }

        return $password;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    private function validUsername(string $localPart, string $domain): string
    {
        $localPart = strtolower(trim($localPart));
        $domain    = strtolower(trim($domain));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $localPart) || str_contains($localPart, '..')) { // "_" allowed: pm10_ipmi_hq1_acme (E-024)
            throw new InvalidArgumentException('local_part');
        }
        if ($this->db->table('local_domains')->where('domain', $domain)->countAllResults() === 0) {
            throw new InvalidArgumentException('domain');
        }

        return "{$localPart}@{$domain}";
    }

    // ------------------------------------------------------------------ Local domains

    public function addDomain(string $domain, ?string $note): void
    {
        try {
            $domain = SenderRules::normalizePattern($domain);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('domain');
        }
        if (str_contains($domain, '@')) {
            throw new InvalidArgumentException('domain');
        }
        $this->db->table('local_domains')->ignore(true)->insert(['domain' => $domain, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** The last domain cannot be deleted (the nodes would reject all mail), nor one still used by identities. */
    public function deleteDomain(int $id): void
    {
        $row = $this->db->table('local_domains')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return;
        }
        if ($this->db->table('local_domains')->countAllResults() <= 1) {
            throw new InvalidArgumentException('last_domain');
        }
        if ($this->db->table('smtp_identities')->like('username', '@' . $row['domain'], 'before')->countAllResults() > 0) {
            throw new InvalidArgumentException('domain_in_use');
        }
        $this->db->table('local_domains')->where('id', $id)->delete();
    }

    // ------------------------------------------------------------------ Admin networks

    public function addNetwork(string $network, ?string $note): void
    {
        $network = self::normalizeNetwork($network);
        $this->db->table('admin_networks')->ignore(true)->insert(['network' => $network, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function deleteNetwork(int $id): void
    {
        $this->db->table('admin_networks')->where('id', $id)->delete();
    }

    /** IPv4/IPv6 address or CIDR network, normalized (host bits are kept as entered, like fail2ban). */
    public static function normalizeNetwork(string $network): string
    {
        $network = strtolower(trim($network));
        [$ip, $prefix] = array_pad(explode('/', $network, 2), 2, null);
        $packed = @inet_pton((string) $ip);
        if ($packed === false) {
            throw new InvalidArgumentException('network');
        }
        $maxPrefix = strlen($packed) === 4 ? 32 : 128;
        if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $maxPrefix || (int) $prefix < 8)) {
            throw new InvalidArgumentException('network');
        }

        return inet_ntop($packed) . ($prefix !== null ? '/' . (int) $prefix : '');
    }

    // ------------------------------------------------------------------ Config files

    /**
     * Config files for the nodes. local-domains and admin-networks are only included when they
     * contain entries, so an empty table can never wipe the node's working configuration.
     *
     * @return array<string, string>
     */
    public function renderFiles(): array
    {
        $header = "# generated by the Ravenkeep web app, do not edit\n";
        $users  = $this->db->table('smtp_identities')->select('username, password_hash')->where('enabled', 1)->orderBy('username')->get()->getResultArray();
        $files  = ['smtp-users' => $header . implode('', array_map(static fn ($u) => "{$u['username']}:{BLF-CRYPT}{$u['password_hash']}\n", $users))];

        $domains = array_column($this->db->table('local_domains')->select('domain')->orderBy('domain')->get()->getResultArray(), 'domain');
        if ($domains !== []) {
            $files['local-domains'] = implode("\n", $domains) . "\n"; // read by Postfix as a list: no comments
        }
        $networks = array_column($this->db->table('admin_networks')->select('network')->orderBy('network')->get()->getResultArray(), 'network');
        if ($networks !== []) {
            $files['admin-networks'] = $header . implode("\n", $networks) . "\n";
        }

        return $files;
    }
}
