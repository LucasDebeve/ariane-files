<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Videos are never uploaded: only links (YouTube, Vimeo, PeerTube or any HTTPS page).
 */
final class VideoLink
{
    public static function isValid(string $url): bool
    {
        if (false === filter_var($url, \FILTER_VALIDATE_URL) || mb_strlen($url) > 500) {
            return false;
        }
        $parts = parse_url($url);

        return 'https' === ($parts['scheme'] ?? null) && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * Privacy-friendly embed URL for known platforms, or null (plain link).
     */
    public static function embedUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $host = (string) preg_replace('/^(www\.|m\.)/', '', $host);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $youtubeId = match (true) {
            'youtu.be' === $host => ltrim($path, '/'),
            \in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) && '/watch' === $path => \is_string($query['v'] ?? null) ? $query['v'] : '',
            \in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) && 1 === preg_match('#^/(embed|shorts|live)/([^/?]+)#', $path, $m) => $m[2],
            default => null,
        };
        if (null !== $youtubeId) {
            return 1 === preg_match('/^[A-Za-z0-9_-]{6,20}$/', $youtubeId) ? 'https://www.youtube-nocookie.com/embed/'.$youtubeId : null;
        }

        if ('vimeo.com' === $host && 1 === preg_match('#^/(\d+)#', $path, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1].'?dnt=1';
        }

        return null;
    }

    public static function platform(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, \PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'youtu') => 'YouTube',
            str_contains($host, 'vimeo') => 'Vimeo',
            default => (string) preg_replace('/^www\./', '', $host),
        };
    }
}
