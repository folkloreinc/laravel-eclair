<?php

namespace Folklore\Eclair\Data;

use Carbon\CarbonImmutable;

/**
 * What Eclair's `embed.probe` reads about a video or audio on a platform: the `embed` object of the
 * probe's metadata. A field the provider doesn't give is null. `authorId` is the provider's id of
 * the author (a YouTube channel, a Vimeo user…), from the provider's answer, never from the URL:
 * compare it with an account you know to check that an embed is your user's own.
 */
final readonly class EmbedMetadata
{
    public function __construct(
        public string $provider,
        public string $id,
        public string $url,
        public ?string $embedUrl = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $authorId = null,
        public ?string $authorName = null,
        public ?string $authorUrl = null,
        public ?string $thumbnailUrl = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?bool $embeddable = null,
        public ?bool $live = null,
        /** `public`, `unlisted` or `private`. */
        public ?string $privacy = null,
        /** The player's HTML, from the provider's oEmbed only. */
        public ?string $html = null,
    ) {}

    /**
     * The `embed` object of probe metadata, or null when it has none.
     *
     * @param  array<string, mixed>|null  $metadata  The probe's metadata
     */
    public static function from(?array $metadata): ?self
    {
        $embed = $metadata['embed'] ?? null;
        if (! is_array($embed)) {
            return null;
        }
        $string = fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $provider = $string($embed['provider'] ?? null);
        $id = $string($embed['id'] ?? null);
        $url = $string($embed['url'] ?? null);
        if ($provider === null || $id === null || $url === null) {
            return null;
        }
        $author = is_array($embed['author'] ?? null) ? $embed['author'] : [];

        return new self(
            provider: $provider,
            id: $id,
            url: $url,
            embedUrl: $string($embed['embedUrl'] ?? null),
            title: $string($embed['title'] ?? null),
            description: $string($embed['description'] ?? null),
            authorId: $string($author['id'] ?? null),
            authorName: $string($author['name'] ?? null),
            authorUrl: $string($author['url'] ?? null),
            thumbnailUrl: $string($embed['thumbnailUrl'] ?? null),
            publishedAt: Dates::read($embed['publishedAt'] ?? null),
            embeddable: is_bool($embed['embeddable'] ?? null) ? $embed['embeddable'] : null,
            live: is_bool($embed['live'] ?? null) ? $embed['live'] : null,
            privacy: $string($embed['privacy'] ?? null),
            html: $string($embed['html'] ?? null),
        );
    }
}
