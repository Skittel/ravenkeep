<?php

namespace App\Libraries\Nodes;

/**
 * Delivers config bundles to an MX node and reads back what the node applied (decision E-009).
 * The node validates and installs the bundle itself (former local MX: rk-apply-config, removed in 0.3.1); the web app
 * never needs root access on a node.
 */
interface ConfigTransport
{
    /**
     * Deliver the files and finally the manifest (the manifest triggers the node).
     *
     * @param array<string, string> $files    file name => content
     * @param string                $manifest manifest.json content
     */
    public function push(array $files, string $manifest): void;

    /**
     * Status written by the node, or null if none exists yet.
     *
     * @return array{applied_hash?: string, applied_at?: string, error?: ?string}|null
     */
    public function readStatus(): ?array;
}
