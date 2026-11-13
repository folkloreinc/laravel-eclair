<?php

namespace Folklore\Eclair\Panneau;

use Folklore\Eclair\Eclair;
use Folklore\Eclair\Exceptions\EclairException;
use Folklore\Eclair\Exceptions\RequestFailed;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Panneau\Contracts\EmbedResolver;

/**
 * Panneau's `embed` field through Eclair (`embed.probe`): the provider's API with the project's
 * credentials, so the value has the duration that oEmbed doesn't give. Bound to Panneau's
 * `EmbedResolver` when `eclair.panneau.embeds` is on and Panneau is installed.
 *
 * A URL that no provider of Eclair recognizes, or that the project doesn't allow, resolves to null:
 * Panneau then keeps the URL alone. Answers are cached as Panneau's own resolver caches them
 * (`panneau.embeds.cache_ttl`).
 */
class EclairEmbedResolver implements EmbedResolver
{
    /** Display names of the providers, as oEmbed gives them. */
    private const array PROVIDERS = [
        'youtube' => 'YouTube',
        'vimeo' => 'Vimeo',
        'dailymotion' => 'Dailymotion',
        'soundcloud' => 'SoundCloud',
        'spotify' => 'Spotify',
        'tiktok' => 'TikTok',
        'instagram' => 'Instagram',
    ];

    public function __construct(
        private readonly Eclair $eclair,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function resolve(string $url): ?array
    {
        $ttl = $this->config->get('panneau.embeds.cache_ttl', 86400);

        /** @var array<string, mixed>|null */
        return $this->cache->remember(
            'eclair:embeds:'.hash('sha256', $url),
            is_int($ttl) ? $ttl : 86400,
            fn (): ?array => $this->probe($url),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function probe(string $url): ?array
    {
        try {
            $result = $this->eclair->probe($this->eclair->embed($url), timeout: 10);
        } catch (EclairException $exception) {
            // A URL Eclair refuses (422) is no embed. Anything else is Eclair's trouble, reported:
            // Panneau keeps the URL alone, and resolves it again on the next save
            if (! $exception instanceof RequestFailed || $exception->status !== 422) {
                report($exception);
            }

            return null;
        }

        $embed = $result->embed();
        if ($embed === null) {
            return null;
        }

        $metadata = $result->metadata ?? [];
        $kind = $metadata['kind'] ?? null;
        $width = $metadata['width'] ?? null;
        $height = $metadata['height'] ?? null;
        $duration = $metadata['duration'] ?? null;

        return array_filter([
            'url' => $url,
            'type' => match ($kind) {
                'video' => 'video',
                'image' => 'photo',
                default => 'rich',
            },
            'title' => $embed->title,
            'provider' => self::PROVIDERS[$embed->provider] ?? $embed->provider,
            'thumbnailUrl' => $embed->thumbnailUrl,
            'html' => $embed->html ?? self::iframe($embed->embedUrl, $width, $height),
            'width' => is_int($width) ? $width : null,
            'height' => is_int($height) ? $height : null,
            'duration' => is_int($duration) || is_float($duration) ? (int) round($duration) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * A player for providers whose API gives no HTML: the provider's own player URL, escaped.
     */
    private static function iframe(?string $player, mixed $width, mixed $height): ?string
    {
        if ($player === null || ! str_starts_with($player, 'https://')) {
            return null;
        }

        return sprintf(
            '<iframe src="%s" width="%d" height="%d" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen></iframe>',
            htmlspecialchars($player, ENT_QUOTES),
            is_int($width) ? $width : 1280,
            is_int($height) ? $height : 720,
        );
    }
}
