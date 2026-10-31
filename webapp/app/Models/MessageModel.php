<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Received messages (one row per message and recipient), imported by the collector.
 */
class MessageModel extends Model
{
    public const STATUSES = ['accepted', 'rejected', 'pending', 'blocked'];

    protected $table         = 'messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [];

    /** Columns searched by the free-text search. */
    private const SEARCH_COLUMNS = [
        'subject', 'envelope_sender', 'envelope_recipient', 'header_from', 'header_to',
        'client_ip', 'client_hostname', 'client_helo', 'sasl_username',
    ];

    /**
     * Apply status and search filters.
     *
     * @param array{status?: string|null, q?: string|null} $filter
     */
    public function filtered(array $filter): static
    {
        if (! empty($filter['status']) && in_array($filter['status'], self::STATUSES, true)) {
            $this->where('status', $filter['status']);
        }
        $query = trim((string) ($filter['q'] ?? ''));
        if ($query !== '') {
            $this->groupStart();
            foreach (self::SEARCH_COLUMNS as $i => $column) {
                $i === 0 ? $this->like($column, $query) : $this->orLike($column, $query);
            }
            $this->groupEnd();
        }

        return $this;
    }

    /**
     * Number of messages per status, for the sidebar.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->builder()->select('status, COUNT(*) AS n')->groupBy('status')->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
