<?php

namespace App\Support\Platform;

final class PortalUrl
{
    public static function base(): string
    {
        return sprintf(
            '%s://%s',
            config('platform.portal.scheme', 'https'),
            config('platform.portal.host', 'new.stelfaro.com'),
        );
    }

    public static function path(string $path = '/'): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    public static function app(string $key, string $path = '/'): string
    {
        $prefix = config("platform.paths.{$key}", '/'.$key);

        return rtrim(self::base().'/'.trim($prefix, '/'), '/').'/'.ltrim($path, '/');
    }
}
