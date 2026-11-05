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

if (! function_exists('rk_tag_class')) {
    /** CSS class for a tag chip: the colour depends on the tag type (prefix before ":"). */
    function rk_tag_class(string $name): string
    {
        $type = str_contains($name, ':') ? explode(':', $name, 2)[0] : '';

        return 'tag tag-c' . ($type === '' ? 0 : (crc32($type) % 7) + 1);
    }
}

if (! function_exists('rk_tag_label')) {
    /** Display form of a tag: "typ:nas" is shown as "typ - nas" (stored and entered with ":"). */
    function rk_tag_label(string $name): string
    {
        return str_contains($name, ':') ? implode(' - ', explode(':', $name, 2)) : $name;
    }
}

if (! function_exists('rk_tag_data')) {
    /**
     * Known tags for the tag picker in assets/app.js (JSON, not executed: allowed by the CSP).
     *
     * @param list<string> $names
     */
    function rk_tag_data(array $names): string
    {
        $data = array_map(static fn (string $n): array => ['n' => $n, 'l' => rk_tag_label($n), 'c' => rk_tag_class($n)], $names);

        return '<script type="application/json" id="rk-tags">'
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    }
}

if (! function_exists('rk_tag_picker_attrs')) {
    /** Data attributes of an input that becomes a tag picker ("multi" = comma list, "single" = one tag). */
    function rk_tag_picker_attrs(string $mode = 'multi', array $excludeTypes = []): string
    {
        return ' data-tag-picker="' . $mode . '"'
            . ($excludeTypes === [] ? '' : ' data-tag-exclude="' . esc(implode(',', $excludeTypes), 'attr') . '"')
            . ' data-tag-new="' . esc(lang('Rules.pickerNew'), 'attr') . '"'
            . ' data-tag-none="' . esc(lang('Rules.pickerNone'), 'attr') . '"'
            . ' data-tag-placeholder="' . esc(lang('Rules.pickerPlaceholder'), 'attr') . '"'
            . ' data-tag-remove="' . esc(lang('Rules.removeTag'), 'attr') . '"';
    }
}

if (! function_exists('rk_tag_toggle')) {
    /** Filter parameters with the tag added or removed (sidebar tree / chips). */
    function rk_tag_toggle(array $filter, string $tag): array
    {
        $tags = $filter['tag'] ?? [];
        $tags = in_array($tag, $tags, true) ? array_values(array_diff($tags, [$tag])) : [...$tags, $tag];

        return ['tag' => $tags ?: null, 'page' => null, 'id' => null];
    }
}

if (! function_exists('rk_sender_reason')) {
    /** Readable reason why a sender was not trusted: the check of its allow rule (E-021). */
    function rk_sender_reason(string $check): string
    {
        $key = 'Senders.check_' . $check;

        return lang('Senders.checkFailed', [lang($key) !== $key ? lang($key) : $check]);
    }
}
