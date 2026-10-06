<?php

namespace App\Support;

/**
 * Best-effort, regex-based browser/OS detection for `ActivityLog` rows —
 * no UA-parsing package exists anywhere in this app (confirmed before
 * adding this), so this is a small, deliberately simple parser rather
 * than a new Composer dependency. It covers the common desktop/mobile
 * browsers and platforms well enough for an admin audit log; it is not a
 * full user-agent database and will return nulls for anything unusual.
 */
class UserAgentParser
{
    public static function browser(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        // Order matters: Edge and Opera's own UA strings also contain
        // "Chrome" (and Chrome's contains "Safari"), so the more specific
        // browsers must be checked first.
        return match (true) {
            str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'EdgA/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Safari') => 'Safari',
            str_contains($userAgent, 'MSIE') || str_contains($userAgent, 'Trident') => 'Internet Explorer',
            default => null,
        };
    }

    public static function browserVersion(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $pattern = match (true) {
            str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'EdgA/') => '/Edg[A]?\/([\d.]+)/',
            str_contains($userAgent, 'OPR/') => '/OPR\/([\d.]+)/',
            str_contains($userAgent, 'Chrome') => '/Chrome\/([\d.]+)/',
            str_contains($userAgent, 'Firefox') => '/Firefox\/([\d.]+)/',
            str_contains($userAgent, 'Safari') => '/Version\/([\d.]+)/',
            default => null,
        };

        if (! $pattern || ! preg_match($pattern, $userAgent, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public static function platform(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        // Android UAs also contain "Linux", and iPad/iPhone UAs contain
        // "Mac OS X" — both must be checked before their broader cousin.
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
