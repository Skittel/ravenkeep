<?php

namespace App\Libraries\Messages;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Work on a message (product owner, 06.11.2026): rating ("Status 1", only stored and shown for now),
 * handling ("Status 2": new, open, done, waiting until a date) and notes. Notes are written once
 * and never changed or deleted; the author comes from the sign-in.
 */
class MessageWork
{
    /** Rating; NULL = not rated (default for every message until the content is evaluated) */
    public const RATINGS = ['info', 'ok', 'warning', 'error'];

    public const HANDLING = ['new', 'open', 'done', 'waiting'];

    public const NOTE_MAX = 10000;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array{name?: string, username?: string, oid?: string} $user signed-in user
     */
    public function setHandling(int $messageId, string $handling, ?string $until, array $user, ?string $today = null): void
    {
        if (! in_array($handling, self::HANDLING, true)) {
            throw new InvalidArgumentException('handling');
        }
        $date = null;
        if ($handling === 'waiting') {
            $date = self::date($until);
            if ($date === null || $date <= ($today ?? gmdate('Y-m-d'))) {
                throw new InvalidArgumentException('handling_until');
            }
        }
        $this->db->table('messages')->where('id', $messageId)->update([
            'handling'       => $handling,
            'handling_until' => $date,
            'handling_by'    => self::author($user),
            'handling_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @param array{name?: string, username?: string, oid?: string} $user */
    public function addNote(int $messageId, string $body, array $user): int
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || mb_strlen($body) > self::NOTE_MAX) {
            throw new InvalidArgumentException('note');
        }
        $this->db->table('message_notes')->insert([
            'message_id'      => $messageId,
            'author_name'     => self::author($user),
            'author_username' => isset($user['username']) ? mb_substr((string) $user['username'], 0, 255) : null,
            'author_oid'      => isset($user['oid']) ? mb_substr((string) $user['oid'], 0, 64) : null,
            'body'            => $body,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /** @return list<array{id: int, author_name: string, author_username: ?string, body: string, created_at: string}> oldest first */
    public function notes(int $messageId): array
    {
        return $this->db->table('message_notes')->select('id, author_name, author_username, body, created_at')
            ->where('message_id', $messageId)->orderBy('id', 'ASC')->get()->getResultArray();
    }

    /** @return array<int, int> message id => number of notes */
    public function noteCounts(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $rows = $this->db->table('message_notes')->select('message_id, COUNT(*) AS n')
            ->whereIn('message_id', $messageIds)->groupBy('message_id')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'n', 'message_id'));
    }

    private static function author(array $user): string
    {
        $name = trim((string) ($user['name'] ?? '')) ?: trim((string) ($user['username'] ?? '')) ?: '?';

        return mb_substr($name, 0, 255);
    }

    private static function date(?string $value): ?string
    {
        $value = trim((string) $value);
        $d     = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
