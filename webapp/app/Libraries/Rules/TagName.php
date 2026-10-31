<?php

namespace App\Libraries\Rules;

use InvalidArgumentException;

/**
 * Tag names: lower case, optionally typed by a prefix ("kunde:acme" -> type "kunde", value "acme").
 * Allowed: letters (incl. umlauts), digits and : _ . - /  – maximum 64 characters.
 */
final class TagName
{
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\s+/u', '-', $name) ?? '';
        if ($name === '' || mb_strlen($name) > 64 || ! preg_match('/^[\p{Ll}\p{Lo}0-9][\p{Ll}\p{Lo}0-9:_.\/-]*$/u', $name)
            || str_ends_with($name, ':') || str_contains($name, '::')) {
            throw new InvalidArgumentException("Invalid tag name: {$name}");
        }

        return $name;
    }

    /** Type of a tag ("kunde" for "kunde:acme"), or "" for untyped tags. */
    public static function type(string $name): string
    {
        return str_contains($name, ':') ? explode(':', $name, 2)[0] : '';
    }

    /** Value part of a tag ("acme" for "kunde:acme"). */
    public static function value(string $name): string
    {
        return str_contains($name, ':') ? explode(':', $name, 2)[1] : $name;
    }
}
