<?php

/**
 * View helpers for the Ravenkeep UI.
 */

if (! function_exists('rk_time')) {
    /** Format a UTC database timestamp in the display time zone. */
    function rk_time(?string $utc, string $format = 'd.m.Y H:i:s'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $tz = new DateTimeZone(config('Ravenkeep')->displayTimezone);

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
    }
}

if (! function_exists('rk_list_time')) {
    /** Compact time for the message list: time only for today, otherwise date and time. */
    function rk_list_time(string $utc): string
    {
        $tz    = new DateTimeZone(config('Ravenkeep')->displayTimezone);
        $time  = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        return $time->format('Y-m-d') === $today ? $time->format('H:i') : $time->format('d.m. H:i');
    }
}

if (! function_exists('rk_size')) {
    /** Human-readable size. */
    function rk_size(int $bytes): string
    {
        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($bytes < 1024 || $unit === 'MB') {
                return ($unit === 'B' ? $bytes : number_format($bytes, 1, ',', '.')) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return '';
    }
}

if (! function_exists('rk_query')) {
    /** URL of the message list with the current filters, overriding the given parameters. */
    function rk_query(array $current, array $override = []): string
    {
        $params = array_filter(array_merge($current, $override), static fn ($v) => $v !== null && $v !== '');

        return site_url('/') . ($params === [] ? '' : '?' . http_build_query($params));
    }
}
