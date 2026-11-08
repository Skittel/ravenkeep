<?php

namespace App\Libraries;

/**
 * Text that is labelled as a Latin character set (ISO-8859-1, Windows-1252, US-ASCII) but really is
 * UTF-8 (T-29; e.g. one monitoring tool labels subject and body "iso-8859-1" and sends UTF-8, so
 * "–" became "â" and "ä" became "Ã¤").
 *
 * Only repaired if the label is Latin AND the bytes contain UTF-8 sequences. Real Latin-1 text with
 * umlauts practically never does (a single byte 0xE4 for "ä" is no UTF-8 sequence), so correctly
 * labelled messages stay as they are. Mixed text (UTF-8 template with Latin-1 names, seen in practice)
 * keeps its UTF-8 sequences and reads the remaining bytes as Windows-1252.
 */
final class Charset
{
    private const LATIN = ['iso-8859-1', 'iso8859-1', 'latin1', 'l1', 'iso-8859-15', 'windows-1252', 'cp1252', 'us-ascii', 'ascii'];

    public static function isLatinLabel(?string $charset): bool
    {
        return $charset !== null && in_array(strtolower(trim($charset, " \"'")), self::LATIN, true);
    }

    /** Bytes that are valid UTF-8 and contain at least one non-ASCII character. */
    public static function isUtf8Text(string $bytes): bool
    {
        return preg_match('/[\x80-\xFF]/', $bytes) === 1 && mb_check_encoding($bytes, 'UTF-8');
    }

    /**
     * Bytes that mix UTF-8 with single Latin bytes (a UTF-8 template filled with Latin-1 names): UTF-8
     * sequences are kept, other bytes >= 0x80 are read as Windows-1252. Null if there is no UTF-8
     * sequence at all (then the text really is Latin and decoding by the label is right).
     */
    public static function mixedToUtf8(string $bytes): ?string
    {
        if (self::isUtf8Text($bytes)) {
            return $bytes;
        }
        $utf8 = '[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';
        if (preg_match('/' . $utf8 . '/', $bytes) !== 1) {
            return null;
        }

        return (string) preg_replace_callback('/(' . $utf8 . ')|([\x80-\xFF])/',
            static fn (array $m): string => $m[1] !== '' ? $m[1] : mb_convert_encoding($m[2], 'UTF-8', 'Windows-1252'), $bytes);
    }

    /** Body bytes of a part labelled $charset, if they are (partly) UTF-8; otherwise null (decode as labelled). */
    public static function mislabelledBody(string $bytes, ?string $charset): ?string
    {
        return self::isLatinLabel($charset) ? self::mixedToUtf8($bytes) : null;
    }

    /**
     * Repair a header value that was decoded with a Latin charset label although it is UTF-8.
     * $raw is the header as received; it must contain an encoded word with a Latin charset.
     */
    public static function repairHeader(?string $decoded, string $raw): ?string
    {
        if ($decoded === null || $decoded === '' || ! preg_match_all('/=\?([^?*]+)(?:\*[^?]*)?\?[QqBb]\?/', $raw, $m)
            || array_filter($m[1], [self::class, 'isLatinLabel']) === []) {
            return $decoded;
        }
        foreach (['ISO-8859-1', 'Windows-1252'] as $latin) {
            $bytes = mb_convert_encoding($decoded, $latin, 'UTF-8');
            if (mb_convert_encoding($bytes, 'UTF-8', $latin) === $decoded && ($fixed = self::mixedToUtf8($bytes)) !== null) {
                return $fixed;
            }
        }

        return $decoded;
    }

    /** Value of a header in the header section of a raw message, unfolded ('' if missing). */
    public static function rawHeader(string $eml, string $name): string
    {
        $end    = strpos($eml, "\r\n\r\n");
        $end    = $end === false ? strpos($eml, "\n\n") : $end;
        $header = $end === false ? $eml : substr($eml, 0, $end);
        if (preg_match('/^' . preg_quote($name, '/') . ':(.*(?:\r?\n[ \t].*)*)/mi', $header, $m) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/\r?\n[ \t]+/', ' ', $m[1]));
    }
}
