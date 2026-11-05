<?php

namespace App\Libraries\Inventory;

use App\Libraries\Rules\AddressTags;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\SenderTags;
use App\Libraries\Rules\TagService;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Clearing up recipient and sender addresses that could not be resolved (decisions E-019, E-020): list
 * them, create the missing customer, location, device or source, or map an address to an existing device.
 */
class AddressService
{
    public function __construct(private readonly BaseConnection $db, private readonly RuleRunner $runner)
    {
    }

    /**
     * Unresolved addresses with message count, first/last message and unknown parts:
     * recipient addresses (direction "to") and verified sender addresses of customer domains ("from", E-020).
     *
     * @return list<array{direction: string, address: string, messages: int, first: string, last: string, last_id: int, customer: ?string, unknown: list<array{part: string, value: string}>}>
     */
    public function unknown(): array
    {
        $rows = $this->db->query(self::UNKNOWN_SQL . ' ORDER BY last DESC')->getResultArray();
        if ($rows === []) {
            return [];
        }
        $parts = [];
        foreach ($this->db->table('messages')->select('id, address_unknown, sender_unknown')
            ->whereIn('id', array_column($rows, 'last_id'))->get()->getResultArray() as $m) {
            $parts[$m['id']] = $m;
        }
        $domains = (new Inventory($this->db, $this->runner))->addressLookup()['domains'];

        return array_map(static fn (array $r): array => [
            'direction' => $r['direction'],
            'address'   => $r['address'],
            'messages'  => (int) $r['messages'],
            'first'     => $r['first'],
            'last'      => $r['last'],
            'last_id'   => (int) $r['last_id'],
            'customer'  => self::customerOf($r['address'], $r['direction'], $domains),
            'unknown'   => json_decode((string) ($parts[$r['last_id']][$r['direction'] === 'from' ? 'sender_unknown' : 'address_unknown'] ?? '[]'), true) ?: [],
        ], $rows);
    }

    private const UNKNOWN_SQL = "
        SELECT 'to' AS direction, LOWER(TRIM(BOTH '<>' FROM envelope_recipient)) AS address, COUNT(*) AS messages,
               MIN(received_at) AS first, MAX(received_at) AS last, MAX(id) AS last_id
        FROM messages WHERE address_unknown IS NOT NULL GROUP BY address
        UNION ALL
        SELECT 'from', LOWER(TRIM(BOTH '<>' FROM header_from_address)), COUNT(*), MIN(received_at), MAX(received_at), MAX(id)
        FROM messages WHERE sender_unknown IS NOT NULL GROUP BY LOWER(TRIM(BOTH '<>' FROM header_from_address))";

    public function countUnknown(): int
    {
        return (int) ($this->db->query('SELECT COUNT(*) AS n FROM (' . self::UNKNOWN_SQL . ') u')->getRow()->n ?? 0);
    }

    /**
     * Senders of customer domains that were not trusted (SPF and DKIM must pass, E-020). Cleared in
     * DNS / on the sending server, not here; listed for information.
     *
     * @return list<array{address: string, messages: int, last: string, last_id: int, reason: string}>
     */
    public function unverified(): array
    {
        $rows = $this->db->query("
            SELECT LOWER(TRIM(BOTH '<>' FROM header_from_address)) AS address, COUNT(*) AS messages, MAX(received_at) AS last, MAX(id) AS last_id
            FROM messages WHERE sender_unverified IS NOT NULL GROUP BY address ORDER BY last DESC")->getResultArray();
        if ($rows === []) {
            return [];
        }
        $reasons = array_column($this->db->table('messages')->select('id, sender_unverified')
            ->whereIn('id', array_column($rows, 'last_id'))->get()->getResultArray(), 'sender_unverified', 'id');

        return array_map(static fn (array $r): array => ['messages' => (int) $r['messages'], 'last_id' => (int) $r['last_id'],
            'reason' => (string) ($reasons[$r['last_id']] ?? '')] + $r, $rows);
    }

    /** Customer code of an address: the customer group of a recipient, the customer of a sender domain. */
    private static function customerOf(string $address, string $direction, array $domains): ?string
    {
        return $direction === 'from'
            ? SenderTags::customerOf(substr((string) strrchr($address, '@'), 1), $domains)
            : (AddressTags::split($address)['customer'] ?? null);
    }

    /** @return list<array{id: int, address: string, device_id: int, path: string, device_name: string, customer_id: int, customer_name: string}> */
    public function mappings(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'device_id' => (int) $r['device_id'], 'customer_id' => (int) $r['customer_id']] + $r,
            $this->db->table('address_map a')->select('a.id, a.address, a.device_id, d.path, d.name AS device_name, c.id AS customer_id, c.name AS customer_name')
                ->join('devices d', 'd.id = a.device_id')->join('customers c', 'c.id = d.customer_id')
                ->orderBy('a.address')->get()->getResultArray());
    }

    /** Assign an address to an existing device (replaces an earlier assignment of the address). */
    public function map(string $address, int $deviceId): void
    {
        $address = self::normalizeAddress($address);
        if ($this->db->table('devices')->where('id', $deviceId)->countAllResults() === 0) {
            throw new InvalidArgumentException('device');
        }
        $this->db->table('address_map')->where('address', $address)->delete();
        $this->db->table('address_map')->insert(['address' => $address, 'device_id' => $deviceId, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->runner->bumpVersion();
    }

    public function unmap(int $id): void
    {
        $this->db->table('address_map')->where('id', $id)->delete();
        $this->runner->bumpVersion();
    }

    /**
     * Create a missing value named by an address. $kind: customer, device, location or source;
     * device and location belong to the customer of the address ($direction "to" recipient, "from" sender).
     */
    public function create(string $address, string $kind, string $value, string $direction = 'to'): void
    {
        $inventory = new Inventory($this->db, $this->runner);
        $customer  = self::customerOf(self::normalizeAddress($address), $direction, $inventory->addressLookup()['domains']);
        $code      = Inventory::normalizeCode($value);

        if ($kind === AddressTags::CUSTOMER) {
            $inventory->createCustomer($code, $code, null);

            return;
        }
        if ($kind === AddressTags::SOURCE) {
            (new TagService($this->db, $this->runner))->create('source:' . $code);

            return;
        }
        $row = $customer === null ? null : $this->db->table('customers')->select('id')->where('code', $customer)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('customer');
        }
        match ($kind) {
            AddressTags::DEVICE   => $inventory->createDevice((int) $row['id'], null, $code, $code, null, []),
            AddressTags::LOCATION => $inventory->createLocation((int) $row['id'], $code, $code, null),
            default               => throw new InvalidArgumentException('kind'),
        };
    }

    public static function normalizeAddress(string $address): string
    {
        $address = strtolower(trim($address, "<> \t"));
        if (! preg_match('/^[^@\s]+@[^@\s]+$/', $address) || strlen($address) > 255) {
            throw new InvalidArgumentException('address');
        }

        return $address;
    }
}
