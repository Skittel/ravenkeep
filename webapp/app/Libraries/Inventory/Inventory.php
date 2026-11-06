<?php

namespace App\Libraries\Inventory;

use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagName;
use App\Libraries\Rules\TagTypes;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Customers, locations and devices with their automatic tags:
 *   customer  -> kunde:<customer>
 *   location  -> standort:<customer>.<location>
 *   device    -> geraet:<path>   (path = customer.device, E-016)
 * plus the tags assigned to a device. Messages from a device's SMTP login get all of them.
 *
 * Codes are fixed after creation (they are part of tags and SMTP user names). Every change that
 * affects tags bumps the rules version, so stored messages are re-evaluated in the background.
 */
class Inventory
{
    public const RESERVED_TAG_TYPES = ['kunde', 'standort', 'geraet'];

    public function __construct(private readonly BaseConnection $db, private readonly RuleRunner $runner)
    {
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $code)) {
            throw new InvalidArgumentException('code');
        }

        return $code;
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) {
            throw new InvalidArgumentException('name');
        }

        return $name;
    }

    // ------------------------------------------------------------------ Customers

    public function createCustomer(string $code, string $name, ?string $note): int
    {
        $code = self::normalizeCode($code);
        if ($this->db->table('customers')->where('code', $code)->countAllResults() > 0) {
            throw new InvalidArgumentException('code_exists');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('customers')->insert(['code' => $code, 'name' => self::validName($name), 'note' => $note, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->runner->bumpVersion(); // addresses are read with the known customers (E-019)

        return $id;
    }

    public function updateCustomer(int $id, string $name, ?string $note): void
    {
        $this->db->table('customers')->where('id', $id)->update(['name' => self::validName($name), 'note' => $note, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function deleteCustomer(int $id): void
    {
        if ($this->db->table('locations')->where('customer_id', $id)->countAllResults() > 0
            || $this->db->table('devices')->where('customer_id', $id)->countAllResults() > 0
            || $this->sharedDevices($id) !== []) {
            throw new InvalidArgumentException('not_empty');
        }
        $this->db->table('customers')->where('id', $id)->delete();
        $this->runner->bumpVersion();
    }

    /**
     * Devices of other customers that also belong to this customer ("kunde:<code>" device tag).
     *
     * @return list<array{id: int, path: string, name: string, customer_id: int, customer_name: string}>
     */
    public function sharedDevices(int $customerId): array
    {
        $code = $this->db->table('customers')->select('code')->where('id', $customerId)->get()->getRow()->code ?? null;
        if ($code === null) {
            return [];
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'customer_id' => (int) $r['customer_id']] + $r,
            $this->db->table('device_tags dt')->select('d.id, d.path, d.name, d.customer_id, c.name AS customer_name')
                ->join('tags t', 't.id = dt.tag_id')->join('devices d', 'd.id = dt.device_id')->join('customers c', 'c.id = d.customer_id')
                ->where('t.name', 'kunde:' . $code)->orderBy('d.path')->get()->getResultArray());
    }

    // ------------------------------------------------------------------ Locations

    public function createLocation(int $customerId, string $code, string $name, ?string $note): int
    {
        $code = self::normalizeCode($code);
        $this->customer($customerId);
        if ($this->db->table('locations')->where(['customer_id' => $customerId, 'code' => $code])->countAllResults() > 0) {
            throw new InvalidArgumentException('code_exists');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('locations')->insert(['customer_id' => $customerId, 'code' => $code, 'name' => self::validName($name),
            'note' => $note, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->runner->bumpVersion(); // addresses are read with the known locations (E-018)

        return $id;
    }

    public function updateLocation(int $id, string $name, ?string $note): void
    {
        $this->db->table('locations')->where('id', $id)->update(['name' => self::validName($name), 'note' => $note, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function deleteLocation(int $id): void
    {
        if ($this->db->table('devices')->where('location_id', $id)->countAllResults() > 0) {
            throw new InvalidArgumentException('not_empty');
        }
        $this->db->table('locations')->where('id', $id)->delete();
        $this->runner->bumpVersion();
    }

    // ------------------------------------------------------------------ Devices

    /** @param list<string> $tags additional tags (normalized, reserved types rejected) */
    public function createDevice(int $customerId, ?int $locationId, string $code, string $name, ?string $note, array $tags, ?int $timeoutProfileId = null): int
    {
        $customer = $this->customer($customerId);
        $code     = self::normalizeCode($code);
        // identity = customer + device code (E-016): moving the device keeps its path and history
        $path = $customer['code'] . '.' . $code;
        if ($locationId !== null
            && $this->db->table('locations')->where(['id' => $locationId, 'customer_id' => $customerId])->countAllResults() === 0) {
            throw new InvalidArgumentException('location');
        }
        if ($this->db->table('devices')->where('path', $path)->countAllResults() > 0) {
            throw new InvalidArgumentException('code_exists');
        }
        $tagNames = $this->ownDeviceTags($tags, $customer['code']);

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('devices')->insert(['customer_id' => $customerId, 'location_id' => $locationId, 'code' => $code, 'path' => $path,
            'name' => self::validName($name), 'note' => $note, 'timeout_profile_id' => $this->validProfile($timeoutProfileId),
            'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->setDeviceTags($id, $tagNames);
        $this->db->transComplete();
        $this->runner->bumpVersion();

        return $id;
    }

    /**
     * @param list<string>   $tags
     * @param int|false|null $locationId new location of the same customer (the device moves: path, monitoring
     *                                   and history stay, the location tag follows), null = none, false = unchanged
     */
    public function updateDevice(int $id, string $name, ?string $note, array $tags, ?int $timeoutProfileId = null, int|false|null $locationId = false): void
    {
        $owner    = $this->db->table('devices d')->select('d.customer_id, c.code')->join('customers c', 'c.id = d.customer_id')
            ->where('d.id', $id)->get()->getRowArray() ?? throw new InvalidArgumentException('device');
        $tagNames = $this->ownDeviceTags($tags, $owner['code']);
        $data = ['name' => self::validName($name), 'note' => $note, 'timeout_profile_id' => $this->validProfile($timeoutProfileId), 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($locationId !== false) {
            if ($locationId !== null
                && $this->db->table('locations')->where(['id' => $locationId, 'customer_id' => $owner['customer_id']])->countAllResults() === 0) {
                throw new InvalidArgumentException('location');
            }
            $data['location_id'] = $locationId;
        }
        $this->db->transStart();
        $this->db->table('devices')->where('id', $id)->update($data);
        $this->setDeviceTags($id, $tagNames);
        $this->db->transComplete();
        $this->runner->bumpVersion();
    }

    public function deleteDevice(int $id): void
    {
        if ($this->db->table('smtp_identities')->where('device_id', $id)->countAllResults() > 0) {
            throw new InvalidArgumentException('has_login');
        }
        $this->db->table('devices')->where('id', $id)->delete();
        $this->runner->bumpVersion();
    }

    /**
     * All tags of a device: automatic (customer, location, device) and assigned ones.
     *
     * @return list<string>
     */
    public function deviceTags(int $deviceId): array
    {
        return $this->deviceTagMap([$deviceId])[$deviceId] ?? [];
    }

    /**
     * Tags per device for many devices at once.
     *
     * @param list<int>|null $deviceIds null = all devices
     *
     * @return array<int, list<string>>
     */
    public function deviceTagMap(?array $deviceIds = null): array
    {
        $builder = $this->db->table('devices d')->select('d.id, d.path, c.code AS customer, l.code AS location')
            ->join('customers c', 'c.id = d.customer_id')->join('locations l', 'l.id = d.location_id', 'left');
        if ($deviceIds !== null) {
            if ($deviceIds === []) {
                return [];
            }
            $builder->whereIn('d.id', $deviceIds);
        }
        $map = [];
        foreach ($builder->get()->getResultArray() as $d) {
            $tags = ['kunde:' . $d['customer']];
            if ($d['location'] !== null) {
                $tags[] = 'standort:' . $d['customer'] . '.' . $d['location'];
            }
            $tags[]               = 'geraet:' . $d['path'];
            $map[(int) $d['id']] = $tags;
        }
        if ($map !== []) {
            $assigned = $this->db->table('device_tags dt')->select('dt.device_id, t.name')->join('tags t', 't.id = dt.tag_id')
                ->whereIn('dt.device_id', array_keys($map))->orderBy('t.name')->get()->getResultArray();
            foreach ($assigned as $row) {
                $map[(int) $row['device_id']][] = $row['name'];
            }
        }

        return $map;
    }

    /**
     * Automatic tags of all customers, locations and devices – also those not used by any message yet
     * (offered in the rule editor's tag picker).
     *
     * @return list<string>
     */
    public function automaticTagNames(): array
    {
        $names = [];
        foreach ($this->db->table('customers')->select('code')->get()->getResultArray() as $c) {
            $names[] = 'kunde:' . $c['code'];
        }
        foreach ($this->db->table('locations l')->select('c.code AS customer, l.code')->join('customers c', 'c.id = l.customer_id')->get()->getResultArray() as $l) {
            $names[] = 'standort:' . $l['customer'] . '.' . $l['code'];
        }
        foreach ($this->db->table('devices')->select('path')->get()->getResultArray() as $d) {
            $names[] = 'geraet:' . $d['path'];
        }

        return $names;
    }

    /**
     * Known values for reading recipient addresses (AddressTags, E-019): customer codes, locations
     * ("customer.location"), device paths ("customer.device"), sources (values of "source:" tags) and
     * the address map (address => device path) and the allow rules with a tag meaning (E-021).
     *
     * @return array{customers: list<string>, locations: list<string>, devices: list<string>, sources: list<string>, map: array<string, string>,
     *     senders: list<array{pattern: string, auth_check: string, customer: ?string, source: ?string}>}
     */
    public function addressLookup(): array
    {
        $locations = $this->db->table('locations l')->select("CONCAT(c.code, '.', l.code) AS k", false)
            ->join('customers c', 'c.id = l.customer_id')->get()->getResultArray();
        $sources = array_merge( // existing source tags and sources set by rules
            array_column($this->db->table('tags')->select('name')->like('name', 'source:', 'after')->get()->getResultArray(), 'name'),
            array_column($this->db->table('rule_actions')->select('value AS name')->where('type', 'add_tag')->like('value', 'source:', 'after')->get()->getResultArray(), 'name'),
        );
        $map     = $this->db->table('address_map a')->select('a.address, d.path')->join('devices d', 'd.id = a.device_id')->get()->getResultArray();
        $senders = $this->db->table('sender_rules r')->select('r.pattern, r.auth_check, c.code AS customer, r.tag_source AS source')
            ->join('customers c', 'c.id = r.tag_customer_id', 'left')->where('r.action', 'allow')
            ->groupStart()->where('r.tag_customer_id IS NOT NULL')->orWhere('r.tag_source IS NOT NULL')->groupEnd()->get()->getResultArray();

        return [
            'customers' => array_column($this->db->table('customers')->select('code')->get()->getResultArray(), 'code'),
            'locations' => array_column($locations, 'k'),
            'devices'   => array_column($this->db->table('devices')->select('path')->get()->getResultArray(), 'path'),
            'sources'   => array_values(array_unique(array_map(static fn (string $n): string => substr($n, strlen('source:')), $sources))),
            'map'       => array_column($map, 'path', 'address'),
            'senders'   => $senders,
        ];
    }

    /**
     * Tags per device path ("customer.device"), used for devices recognised by their address (E-016).
     *
     * @return array<string, list<string>>
     */
    public function tagsByPath(): array
    {
        $paths  = array_column($this->db->table('devices')->select('id, path')->get()->getResultArray(), 'path', 'id');
        $result = [];
        foreach ($this->deviceTagMap() as $id => $tags) {
            $result[$paths[$id]] = $tags;
        }

        return $result;
    }

    /**
     * Tags per SMTP login (lower-case user name), used when messages are evaluated: the tags of its
     * device and its own tags (E-024). Every login is listed, also one without tags.
     *
     * @return array<string, list<string>>
     */
    public function tagsBySmtpLogin(): array
    {
        $logins = $this->db->table('smtp_identities')->select('id, username, device_id')->get()->getResultArray();
        $map    = $this->deviceTagMap(array_map('intval', array_filter(array_column($logins, 'device_id'))));
        $own    = [];
        foreach ($this->db->table('smtp_identity_tags st')->select('st.identity_id, t.name')->join('tags t', 't.id = st.tag_id')->get()->getResultArray() as $row) {
            $own[(int) $row['identity_id']][] = $row['name'];
        }
        $result = [];
        foreach ($logins as $login) {
            // device tags plus the login's own tags (E-024)
            $result[strtolower($login['username'])] = array_values(array_unique([...($map[(int) $login['device_id']] ?? []), ...($own[(int) $login['id']] ?? [])]));
        }

        return $result;
    }

    /** @return list<string> */
    public static function validTags(array $tags, bool $allowCustomers = false): array
    {
        $names = [];
        foreach ($tags as $tag) {
            if (trim((string) $tag) === '') {
                continue;
            }
            $name = TagName::normalize((string) $tag);
            $type = TagName::type($name);
            // a device may belong to further customers: "kunde:" tags (checked against the customers by ownDeviceTags)
            if (in_array($type, self::RESERVED_TAG_TYPES, true) && ! ($allowCustomers && $type === 'kunde')) {
                throw new InvalidArgumentException('reserved_tag');
            }
            $names[$name] = true;
        }

        return array_keys($names);
    }

    // ------------------------------------------------------------------ Timeout profiles

    public function createProfile(string $name, int $hours, ?string $note): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('name');
        }
        if ($hours < 1 || $hours > 8760) {
            throw new InvalidArgumentException('hours');
        }
        if ($this->db->table('timeout_profiles')->where('name', $name)->countAllResults() > 0) {
            throw new InvalidArgumentException('profile_exists');
        }
        $this->db->table('timeout_profiles')->insert(['name' => $name, 'hours' => $hours, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s')]);

        return (int) $this->db->insertID();
    }

    public function updateProfile(int $id, int $hours): void
    {
        if ($hours < 1 || $hours > 8760) {
            throw new InvalidArgumentException('hours');
        }
        $this->db->table('timeout_profiles')->where('id', $id)->update(['hours' => $hours]);
    }

    public function deleteProfile(int $id): void
    {
        $this->db->table('timeout_profiles')->where('id', $id)->delete(); // devices: SET NULL
    }

    /**
     * Monitoring state of all devices with a timeout profile, overdue ones first.
     * last_seen = newest accepted message carrying the device tag (geraet:<path>) – from the
     * device's SMTP login or assigned by a rule (e.g. external services).
     *
     * @param int|null $customerId only devices of this customer
     *
     * @return list<array{id: int, path: string, name: string, customer_name: string, profile: string, hours: int,
     *     last_seen: ?string, due_at: string, overdue: bool, overdue_hours: int}>
     */
    public function monitoring(?int $customerId = null, ?int $now = null): array
    {
        $now     = $now ?? time();
        $builder = $this->db->table('devices d')
            ->select('d.id, d.path, d.name, d.created_at, c.name AS customer_name, p.name AS profile, p.hours,
                (SELECT MAX(m.received_at) FROM message_tags mt JOIN tags t ON t.id = mt.tag_id JOIN messages m ON m.id = mt.message_id
                 WHERE t.name = CONCAT(\'geraet:\', d.path) AND m.status = \'accepted\') AS last_seen', false)
            ->join('customers c', 'c.id = d.customer_id')->join('timeout_profiles p', 'p.id = d.timeout_profile_id');
        if ($customerId !== null) {
            $builder->where('d.customer_id', $customerId);
        }
        $result = [];
        foreach ($builder->get()->getResultArray() as $d) {
            // without any message yet, the interval starts when the device was created
            $base                = strtotime(($d['last_seen'] ?? $d['created_at']) . ' UTC');
            $due                 = $base + (int) $d['hours'] * 3600;
            $d['id']             = (int) $d['id'];
            $d['hours']          = (int) $d['hours'];
            $d['due_at']         = gmdate('Y-m-d H:i:s', $due);
            $d['overdue']        = $now > $due;
            $d['overdue_hours']  = $d['overdue'] ? (int) floor(($now - $due) / 3600) : 0;
            $result[]            = $d;
        }
        usort($result, static fn ($a, $b) => [$b['overdue'], $a['due_at']] <=> [$a['overdue'], $b['due_at']]);

        return $result;
    }

    private function validProfile(?int $id): ?int
    {
        if ($id === null || $id === 0) {
            return null;
        }
        if ($this->db->table('timeout_profiles')->where('id', $id)->countAllResults() === 0) {
            throw new InvalidArgumentException('profile');
        }

        return $id;
    }

    /** @param list<string> $tagNames */
    /**
     * Own tags of a device: free tags plus further customers ("kunde:<code>" of existing customers).
     * The owning customer (path, location, address scheme) stays one; its tag is automatic and left out.
     *
     * @return list<string>
     */
    private function ownDeviceTags(array $tags, string $ownerCode): array
    {
        $names = self::validTags($tags, true);
        $codes = array_column($this->db->table('customers')->select('code')->get()->getResultArray(), 'code');
        $out   = [];
        foreach ($names as $name) {
            if (TagName::type($name) === 'kunde') {
                $code = substr($name, 6);
                if (! in_array($code, $codes, true)) {
                    throw new InvalidArgumentException('customer_tag');
                }
                if ($code === $ownerCode) {
                    continue;
                }
            }
            $out[] = $name;
        }
        (new TagTypes($this->db))->assertKnown($out);

        return $out;
    }

    private function setDeviceTags(int $deviceId, array $tagNames): void
    {
        $this->db->table('device_tags')->where('device_id', $deviceId)->delete();
        foreach ($tagNames as $name) {
            $this->db->table('device_tags')->insert(['device_id' => $deviceId, 'tag_id' => $this->runner->tagId($name)]);
        }
    }

    private function customer(int $id): array
    {
        $customer = $this->db->table('customers')->where('id', $id)->get()->getRowArray();
        if ($customer === null) {
            throw new InvalidArgumentException('customer');
        }

        return $customer;
    }
}
