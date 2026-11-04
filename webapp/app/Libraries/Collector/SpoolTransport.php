<?php

namespace App\Libraries\Collector;

/**
 * Access to the spool of one MX node. The node stores each message as <id>.eml plus <id>.json
 * in "new/"; the .json file is written last and marks a complete entry.
 * Implementations: MailrunnerSpoolTransport (MailRunner receive node, E-014) and LocalSpoolTransport
 * (spool of the former local Postfix MX, kept for tests and existing node rows).
 */
interface SpoolTransport
{
    /**
     * IDs of complete entries (those with a .json file), oldest first.
     *
     * @return list<string>
     */
    public function listEntries(int $limit): array;

    /** Metadata JSON of an entry as string. */
    public function readMetadata(string $id): string;

    /** Original message of an entry, or null if the .eml file is missing. */
    public function readMessage(string $id): ?string;

    /** Remove an entry after it has been stored centrally (.eml first, then .json). */
    public function deleteEntry(string $id): void;
}
