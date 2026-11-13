<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Panneau\EclairEmbedResolver;
use Folklore\Eclair\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Panneau\Contracts\EmbedResolver;
use Panneau\Embeds\OEmbedResolver;

final class PanneauEmbedResolverTest extends TestCase
{
    private const array METADATA = [
        'kind' => 'video',
        'mime' => 'text/html',
        'duration' => 62.4,
        'width' => 1920,
        'height' => 1080,
        'embed' => [
            'provider' => 'vimeo',
            'id' => '76979871',
            'url' => 'https://vimeo.com/76979871',
            'embedUrl' => 'https://player.vimeo.com/video/76979871?h=a"b',
            'title' => 'The New Vimeo Player',
            'thumbnailUrl' => 'https://i.vimeocdn.com/video/452001751-d_1920x1080',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // As Panneau's provider binds its default resolver
        $this->app->singleton(EmbedResolver::class, OEmbedResolver::class);
    }

    public function test_it_replaces_panneaus_resolver_when_on(): void
    {
        $this->assertInstanceOf(OEmbedResolver::class, app(EmbedResolver::class));

        config(['eclair.panneau.embeds' => true]);
        $this->app->forgetInstance(EmbedResolver::class);

        $this->assertInstanceOf(EclairEmbedResolver::class, app(EmbedResolver::class));
    }

    public function test_it_resolves_an_embed_with_its_duration_and_caches_it(): void
    {
        Http::fake(['eclair.example.com/v1/probe' => Http::response(['metadata' => self::METADATA])]);
        $resolver = app(EclairEmbedResolver::class);

        $value = $resolver->resolve('https://vimeo.com/76979871');
        $again = $resolver->resolve('https://vimeo.com/76979871');

        $this->assertSame([
            'url' => 'https://vimeo.com/76979871',
            'type' => 'video',
            'title' => 'The New Vimeo Player',
            'provider' => 'Vimeo',
            'thumbnailUrl' => 'https://i.vimeocdn.com/video/452001751-d_1920x1080',
            // No oEmbed HTML: the provider's player, escaped
            'html' => '<iframe src="https://player.vimeo.com/video/76979871?h=a&quot;b" width="1920" height="1080" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen></iframe>',
            'width' => 1920,
            'height' => 1080,
            'duration' => 62,
        ], $value);
        $this->assertSame($value, $again);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['input'] === ['embed' => 'https://vimeo.com/76979871'] && $request['timeout'] === 10);
    }

    public function test_an_audio_embed_is_an_audio_value(): void
    {
        Http::fake(['eclair.example.com/v1/probe' => Http::response(['metadata' => [
            'kind' => 'audio',
            'mime' => 'text/html',
            'embed' => ['provider' => 'soundcloud', 'id' => 'forss/flickermood', 'url' => 'https://soundcloud.com/forss/flickermood', 'html' => '<iframe></iframe>'],
        ]])]);

        $value = app(EclairEmbedResolver::class)->resolve('https://soundcloud.com/forss/flickermood');

        $this->assertSame(['audio', 'SoundCloud', '<iframe></iframe>'], [$value['type'] ?? null, $value['provider'] ?? null, $value['html'] ?? null]);
    }

    public function test_a_url_eclair_refuses_or_an_unreachable_eclair_resolves_to_nothing(): void
    {
        Http::fakeSequence('eclair.example.com/v1/probe')
            ->push(['title' => 'Unprocessable Content', 'status' => 422, 'errors' => ['/input/embed' => ['is not a page of an embed provider of this server']]], 422)
            ->pushStatus(503);
        $resolver = app(EclairEmbedResolver::class);

        $this->assertNull($resolver->resolve('https://example.com/video/1'));
        $this->assertNull($resolver->resolve('https://vimeo.com/2'));
    }
}
