<?php

namespace App\Libraries\Collector;

use App\Libraries\Charset;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Imports spool entries from MX nodes into the database and the EML archive.
 *
 * Order per entry (safe to interrupt at any point):
 *   1. already imported (node_id + spool_id exists)? -> only delete the spool entry
 *   2. write the EML file to the archive (temp file + rename, fsync)
 *   3. insert the metadata row
 *   4. delete the spool entry on the node
 * A crash between 2 and 3 leaves an archive file that is overwritten on the next run;
 * a crash between 3 and 4 is caught by step 1.
 */
class Collector
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $archivePath,
        private readonly int $batchSize = 500,
    ) {
    }

    /**
     * Collect one node. Returns counters: imported, duplicates, errors.
     *
     * @param array{id: int|string, name: string} $node
     *
     * @return array{imported: int, duplicates: int, errors: int}
     */
    public function collectNode(array $node, SpoolTransport $transport): array
    {
        $result = ['imported' => 0, 'duplicates' => 0, 'errors' => 0];

        foreach ($transport->listEntries($this->batchSize) as $id) {
            try {
                $outcome = $this->importEntry((int) $node['id'], $id, $transport);
                $result[$outcome]++;
            } catch (Throwable $e) {
                $result['errors']++;
                $this->log[] = "{$node['name']}/{$id}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->log;
    }

    /** @return 'imported'|'duplicates' */
    private function importEntry(int $nodeId, string $id, SpoolTransport $transport): string
    {
        if ($this->db->table('messages')->where(['node_id' => $nodeId, 'spool_id' => $id])->countAllResults() > 0) {
            $transport->deleteEntry($id);

            return 'duplicates';
        }

        $meta = json_decode($transport->readMetadata($id), true, 32, JSON_THROW_ON_ERROR);
        if (($meta['id'] ?? null) !== $id) {
            throw new RuntimeException('metadata id does not match file name');
        }
        $eml = $transport->readMessage($id);
        if ($eml === null) {
            throw new RuntimeException('EML file missing');
        }

        $receivedAt = $this->utc($meta['received_at'] ?? 'now');
        $relPath    = $receivedAt->format('Y/m/d') . "/{$id}.eml";
        $this->writeArchive($relPath, $eml);

        $this->db->table('messages')->insert($this->row($nodeId, $id, $meta, $receivedAt, $relPath, $eml));
        $transport->deleteEntry($id);

        return 'imported';
    }

    /** Map the node's metadata JSON to a database row. */
    private function row(int $nodeId, string $id, array $meta, DateTimeImmutable $receivedAt, string $relPath, string $eml): array
    {
        $headers = $meta['headers'] ?? [];
        $client  = $meta['client'] ?? [];
        $match   = $meta['list_match'] ?? null;

        return [
            'node_id'             => $nodeId,
            'spool_id'            => $id,
            'received_at'         => $receivedAt->format('Y-m-d H:i:s'),
            'mode'                => $meta['mode'] ?? 'mx',
            'status'              => $this->status($meta),
            'auth_verdict'        => $meta['auth_verdict'] ?? null,
            'sasl_username'       => $meta['sasl_username'] ?? null,
            'envelope_sender'     => $this->cut($meta['envelope']['sender'] ?? '', 255),
            'envelope_recipient'  => $this->cut($meta['envelope']['recipient'] ?? '', 255),
            'client_ip'           => $this->cut(preg_replace('/^IPv6:/i', '', $client['address'] ?? ''), 45),
            'client_hostname'     => $this->cut($client['hostname'] ?? '', 255),
            'client_ptr'          => $this->cut(implode(', ', $client['ptr'] ?? []), 255),
            'client_helo'         => $this->cut($client['helo'] ?? '', 255),
            'header_from'         => $this->cut(Charset::repairHeader($headers['from'] ?? null, Charset::rawHeader($eml, 'From')), 512),
            'header_from_address' => $this->cut($headers['from_address'] ?? null, 255),
            'header_to'           => Charset::repairHeader($headers['to'] ?? null, Charset::rawHeader($eml, 'To')),
            'subject'             => $this->cut(Charset::repairHeader($headers['subject'] ?? null, Charset::rawHeader($eml, 'Subject')), 998), // T-29
            'header_date'         => $this->cut($headers['date'] ?? null, 128),
            'message_id'          => $this->cut($headers['message_id'] ?? null, 512),
            'size'                => (int) ($meta['size'] ?? strlen($eml)),
            'queue_id'            => $this->cut($meta['queue_id'] ?? '', 32),
            'list_match_pattern'  => $match['pattern'] ?? null,
            'list_match_target'   => $match['target'] ?? null,
            'reasons'             => json_encode($meta['reasons'] ?? [], JSON_UNESCAPED_UNICODE),
            'auth_details'        => isset($meta['auth']) ? json_encode($meta['auth'], JSON_UNESCAPED_UNICODE) : null,
            'eml_path'            => $relPath,
            'eml_sha256'          => hash('sha256', $eml),
            'created_at'          => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** Status of an entry; spool entries written by node version 0.1.1 only had "verdict". */
    private function status(array $meta): string
    {
        if (isset($meta['status'])) {
            return $meta['status'];
        }
        if (($meta['mode'] ?? '') === 'auth') {
            return 'accepted';
        }

        return ($meta['verdict'] ?? '') === 'accepted' ? 'accepted' : 'pending';
    }

    private function writeArchive(string $relPath, string $data): void
    {
        $target = rtrim($this->archivePath, '/') . '/' . $relPath;
        $dir    = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create archive directory {$dir}");
        }
        $tmp = $target . '.tmp';
        $fh  = fopen($tmp, 'wb');
        if ($fh === false || fwrite($fh, $data) !== strlen($data) || ! fflush($fh) || ! fsync($fh)) {
            throw new RuntimeException("Cannot write archive file {$tmp}");
        }
        fclose($fh);
        if (! rename($tmp, $target)) {
            throw new RuntimeException("Cannot move archive file to {$target}");
        }
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
