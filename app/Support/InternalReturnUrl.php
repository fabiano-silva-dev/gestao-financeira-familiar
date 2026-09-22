<?php

namespace App\Support;

use Illuminate\Http\Request;

final class InternalReturnUrl
{
    public static function fromRequest(Request $request, string $routeName): ?string
    {
        $value = trim($request->string('return_to')->toString());

        if ($value === '' || strlen($value) > 2048) {
            return null;
        }

        if (str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }

        $allowedPath = parse_url(route($routeName), PHP_URL_PATH);

        if (! is_string($allowedPath) || $allowedPath === '') {
            return null;
        }

        $parsed = parse_url($value);

        if ($parsed === false
            || isset($parsed['scheme'])
            || isset($parsed['host'])
            || isset($parsed['port'])
            || isset($parsed['user'])) {
            return null;
        }

        $path = $parsed['path'] ?? '';

        if ($path !== $allowedPath) {
            return null;
        }

        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        return $path.$query.$fragment;
    }
}
