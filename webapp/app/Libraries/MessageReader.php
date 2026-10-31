<?php

namespace App\Libraries;

use RuntimeException;
use ZBateson\MailMimeParser\Message;

/**
 * Reads an archived original message (EML) and extracts what the reading pane shows.
 */
class MessageReader
{
    private Message $message;
    private string $raw;

    public function __construct(string $archivePath, string $relativePath)
    {
        $file = rtrim($archivePath, '/') . '/' . $relativePath;
        if (str_contains($relativePath, '..') || ! is_file($file)) {
            throw new RuntimeException('Archived message not found');
        }
        $this->raw     = (string) file_get_contents($file);
        $this->message = Message::from($this->raw, false);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /** Header section exactly as received. */
    public function rawHeaders(): string
    {
        $end = strpos($this->raw, "\r\n\r\n");
        $end = $end === false ? strpos($this->raw, "\n\n") : $end;

        return $end === false ? $this->raw : substr($this->raw, 0, $end);
    }

    public function textBody(): ?string
    {
        return $this->message->getTextContent();
    }

    public function htmlBody(): ?string
    {
        return $this->message->getHtmlContent();
    }

    /**
     * Attachments: name, MIME type and size in bytes (content is not shown).
     *
     * @return list<array{name: string, type: string, size: int}>
     */
    public function attachments(): array
    {
        $list = [];
        foreach ($this->message->getAllAttachmentParts() as $part) {
            $list[] = [
                'name' => $part->getFilename() ?? '(ohne Namen)',
                'type' => $part->getContentType() ?? 'application/octet-stream',
                'size' => strlen((string) $part->getContent()),
            ];
        }

        return $list;
    }
}
