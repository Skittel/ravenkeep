<?php

namespace App\Libraries\Inventory;

use App\Libraries\Rules\AddressTags;
use App\Libraries\Rules\RuleRunner;
use App\Libraries\Rules\TagService;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Clearing up recipient addresses that could not be resolved (decision E-019): list them, create the
 * missing customer, location, device or source, or map an address to an existing device.
 */
class AddressService
{
    public function __construct(private readonly BaseConnection $db, private readonly RuleRunner $runner)
    {
    }

    /**
     * Unresolved recipient addresses with message count, first/last message and unknown parts.
     *
     * @return list<array{address: string, messages: int, first: string, last: string, last_id: int, customer: ?string, unknown: list<array{part: string, value: string}>}>
     */
    public function unknown(): array
    {
        $rows = $this->db->query('
            SELECT LOWER(TRIM(BOTH \'<>\' FROM envelope_recipient)) AS address, COUNT(*) AS messages,
                   MIN(received_at) AS first, MAX(received_at) AS last, MAX(id) AS last_id
            FROM messages WHERE address_unknown IS NOT NULL
            GROUP BY address ORDER BY last DESC')->getResultArray();
        if ($rows === []) {
            return [];
        }
        $parts = array_column($this->db->table('messages')->select('id, address_unknown')
            ->whereIn('id', array_column($rows, 'last_id'))->get()->getResultArray(), 'address_unknown', 'id');

        return array_map(static fn (array $r): array => [
            'address'  => $r['address'],
            'messages' => (int) $r['messages'],
            'first'    => $r['first'],
            'last'     => $r['last'],
            'last_id'  => (int) $r['last_id'],
            'customer' => AddressTags::split($r['address'])['customer'] ?? null,
            'unknown'  => json_decode((string) ($parts[$r['last_id']] ?? '[]'), true) ?: [],
        ], $rows);
    }

    public function countUnknown(): int
    {
        return (int) ($this->db->query('SELECT COUNT(DISTINCT envelope_recipient) AS n FROM messages WHERE address_unknown IS NOT NULL')->getRow()->n ?? 0);
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
     * device and location belong to the customer of the address.
     */
    public function create(string $address, string $kind, string $value): void
    {
        $inventory = new Inventory($this->db, $this->runner);
        $customer  = AddressTags::split(self::normalizeAddress($address))['customer'] ?? null;
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
