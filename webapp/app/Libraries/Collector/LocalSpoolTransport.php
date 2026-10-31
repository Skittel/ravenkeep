<?php

namespace App\Libraries\Collector;

use RuntimeException;

/**
 * Spool transport for a node on the same server (direct filesystem access).
 */
class LocalSpoolTransport implements SpoolTransport
{
    private string $newDir;

    public function __construct(string $spoolPath)
    {
        $this->newDir = rtrim($spoolPath, '/') . '/new';
        if (! is_dir($this->newDir) || ! is_readable($this->newDir)) {
            throw new RuntimeException("Spool directory not readable: {$this->newDir}");
        }
    }

    public function listEntries(int $limit): array
    {
        $ids = [];
        foreach (scandir($this->newDir) ?: [] as $file) {
            if (preg_match('/^([A-Za-z0-9T._-]+)\.json$/', $file, $m)) {
                $ids[] = $m[1];
            }
        }
        sort($ids, SORT_STRING); // IDs start with a timestamp, so this is oldest first

        return array_slice($ids, 0, $limit);
    }

    public function readMetadata(string $id): string
    {
        $data = file_get_contents($this->path($id, 'json'));
        if ($data === false) {
            throw new RuntimeException("Cannot read metadata of spool entry {$id}");
        }

        return $data;
    }

    public function readMessage(string $id): ?string
    {
        $file = $this->path($id, 'eml');
        if (! is_file($file)) {
            return null;
        }
        $data = file_get_contents($file);
        if ($data === false) {
            throw new RuntimeException("Cannot read message of spool entry {$id}");
        }

        return $data;
    }

    public function deleteEntry(string $id): void
    {
        foreach (['eml', 'json'] as $ext) {
            $file = $this->path($id, $ext);
            if (is_file($file) && ! unlink($file)) {
                throw new RuntimeException("Cannot delete {$file}");
            }
        }
    }

    private function path(string $id, string $ext): string
    {
        if (! preg_match('/^[A-Za-z0-9T._-]+$/', $id)) {
            throw new RuntimeException("Invalid spool entry id: {$id}");
        }

        return "{$this->newDir}/{$id}.{$ext}";
    }
}
