<?php

namespace App\Libraries\Nodes;

use RuntimeException;

/**
 * Config transport for a node on the same server: writes into <dataDir>/config-incoming/.
 */
class LocalConfigTransport implements ConfigTransport
{
    private string $incoming;
    private string $statusFile;

    public function __construct(string $dataDir)
    {
        $dataDir          = rtrim($dataDir, '/');
        $this->incoming   = "{$dataDir}/config-incoming";
        $this->statusFile = "{$dataDir}/config-status.json";
    }

    public function push(array $files, string $manifest): void
    {
        if (! is_dir($this->incoming) || ! is_writable($this->incoming)) {
            throw new RuntimeException("Config directory not writable: {$this->incoming}");
        }
        foreach ($files as $name => $content) {
            $this->writeAtomic($name, $content);
        }
        $this->writeAtomic('manifest.json', $manifest); // last: triggers rk-apply-config on the node
    }

    public function readStatus(): ?array
    {
        if (! is_file($this->statusFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->statusFile), true);

        return is_array($data) ? $data : null;
    }

    private function writeAtomic(string $name, string $content): void
    {
        if (! preg_match('/^[a-z0-9.-]+$/', $name)) {
            throw new RuntimeException("Invalid config file name: {$name}");
        }
        $tmp = "{$this->incoming}/.{$name}.tmp";
        if (file_put_contents($tmp, $content) !== strlen($content) || ! rename($tmp, "{$this->incoming}/{$name}")) {
            throw new RuntimeException("Cannot write {$name}");
        }
    }
}
