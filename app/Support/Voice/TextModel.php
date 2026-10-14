<?php

namespace App\Support\Voice;

use Illuminate\Support\Facades\Cache;

/**
 * Where every text the radio and the league voice speak is written: on the server through Anthropic ("anthropic"), or
 * on the workstation by the opencode CLI ("opencode", user 2026-10-14: "Kannst du die ganze Textgenerierung für die
 * Audios mit opencode headless machen … muss hier am Rechner passieren!"). The workstation serves an OpenAI-compatible
 * endpoint (`caster:text-gateway`), reverse-tunnelled to the server's loopback; laravel/ai reaches it as the
 * `opencode` provider (AppServiceProvider). The switch is a cache flag over `esports.voice.provider`, flipped with
 * `php artisan caster:text opencode|anthropic` without a deploy or a stream restart.
 */
final class TextModel
{
    public const PROVIDERS = ['anthropic', 'opencode'];

    private const CACHE_KEY = 'voice:text_provider';

    /** The provider in force: the switched one, else the configured default. */
    public static function active(): string
    {
        $switched = Cache::get(self::CACHE_KEY);

        return in_array($switched, self::PROVIDERS, true) ? $switched : (string) config('esports.voice.provider', 'anthropic');
    }

    public static function switchTo(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException("Unknown text provider [{$provider}].");
        }

        Cache::forever(self::CACHE_KEY, $provider);
    }

    public static function usesOpencode(): bool
    {
        return self::active() === 'opencode';
    }

    /** The laravel/ai provider name for an agent's call. */
    public static function provider(): string
    {
        return self::active();
    }

    /** The agent's own model on Anthropic; on the workstation every call runs on the one opencode model. */
    public static function model(string $anthropicModel): string
    {
        return self::usesOpencode() ? (string) config('esports.voice.opencode.model', 'zai-coding-plan/glm-5.3-flash') : $anthropicModel;
    }

    /** The agent's timeout, raised to the workstation's floor (a CLI start and a round trip through the tunnel). */
    public static function timeout(int $seconds): int
    {
        return self::usesOpencode() ? max($seconds, (int) config('esports.voice.opencode.timeout_seconds', 90)) : $seconds;
    }
}
