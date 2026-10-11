<?php

namespace App\Support\Voice;

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\StreamBot\StreamBotCopy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Where the stream bot's posts meet the league voice (plan "KI-Stimme der
 * Liga", P2). Every place that posts a StreamBotCopy template (the chat
 * rotation, the GG, the profile notes) hands its template, wording and
 * values to compose(); a share post hands its sentence to sentence(). The
 * template is the fallback: it goes out whenever the voice does not (its
 * function switch off, its channel off, cap or budget reached, the request
 * failed, a check rejected the text, the spam gate turned the text down).
 *
 * The voice never adds a post: it only replaces the wording of one the
 * caller sends anyway, and it never writes the link. The model gets the
 * values without `url`, and the app appends the link as the template's last
 * line ("👉 <url>"), so the link a template carried is the link the post
 * carries.
 *
 * Mentions: a person in the values is a `nostr:npub1…` with its `p` tag.
 * compose() keeps both only while the player allows mentions (Settings,
 * Account) and the spam gate's mention pause lets them; otherwise the post
 * names the player in plain text, template and voice alike. The model sees
 * names and role tokens (VoiceMentions), never a key.
 *
 * The spam gate (SpamGate, on by default and independent of the voice
 * switches): the voice's text must pass textSkip() or the template goes out;
 * the template itself is held back only where the caller has other posts to
 * offer instead (`hold_template`, the chat rotation). The caller reports a
 * delivered post with delivered(), which the gate remembers.
 */
final class BotVoice
{
    /**
     * The posts that may speak with the voice: their switch is
     * `esports.voice.functions.<name>`, their channel (language, length,
     * daily cap) `esports.voice.channels.<channel>`, their spam-gate surface.
     *
     * @var array<string, array{channel: string, surface: ?string}>
     */
    public const FUNCTIONS = [
        'chat_rotation' => ['channel' => 'stream_chat', 'surface' => SpamGate::CHAT],
        'chat_gg' => ['channel' => 'gg', 'surface' => SpamGate::CHAT],
        'note_tournament' => ['channel' => 'profile_note', 'surface' => SpamGate::PROFILE],
        'note_free_places' => ['channel' => 'profile_note', 'surface' => SpamGate::PROFILE],
        'note_champion' => ['channel' => 'profile_note', 'surface' => SpamGate::PROFILE],
        'note_pride' => ['channel' => 'profile_note', 'surface' => SpamGate::PROFILE],
        'note_weekly' => ['channel' => 'profile_note', 'surface' => SpamGate::PROFILE],
        'share_post' => ['channel' => 'share_post', 'surface' => null],
    ];

    /**
     * The moment (VoiceCanon::MOMENTS) of each template: which canon entries
     * and example posts fit it. A template not listed is `general`.
     *
     * @var array<string, string>
     */
    public const MOMENTS = [
        'tournament_signup' => 'signup_open',
        'tournament_last_call' => 'last_free_place',
        'tournament_gg' => 'champion',
        'tournament_winner' => 'champion',
        'rank_up' => 'win',
        'season_live' => 'season_start',
        'season_countdown' => 'season_start',
        'tmnf_top' => 'win',
        'tmnf_podium' => 'champion',
        'zap' => 'zap',
        'tournament_note_open' => 'signup_open',
        'tournament_note_places' => 'last_free_place',
        'blockfill_note_week' => 'blockfill_week',
        'blockfill_note_winner' => 'champion',
        'blockfill_note_top' => 'win',
        'blockfill_note_top_burst' => 'win',
        'tmnf_note_winner' => 'champion',
        'tmnf_note_top' => 'win',
        'tmnf_note_top_burst' => 'win',
        'champion_note' => 'champion',
        'pride_note_win' => 'win',
        'pride_note_series_win' => 'win',
        'pride_note_tournament_win' => 'champion',
        'pride_note_tournament_table_win' => 'champion',
        'pride_note_lobby_win' => 'champion',
        'pride_note_climbers' => 'streak',
        'pride_note_prizes' => 'pot_growing',
        'share:rank-up' => 'win',
        'share:block' => 'win',
        'share:tournament' => 'champion',
        'share:wrapped' => 'season_end',
        'share:game' => 'win',
        'share:series' => 'win',
        'share:signup' => 'signup_open',
        'share:blockfill' => 'win',
        'share:tmnf' => 'win',
        'share:hyper' => 'win',
        'share:pong' => 'win',
    ];

    /** How long a share post keeps its wording: the page shows it, the player signs it, the gate compares it. */
    public const SHARE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly LeagueVoice $voice,
        private readonly SpamGate $gate,
    ) {}

    public function enabled(string $function): bool
    {
        return isset(self::FUNCTIONS[$function]) && config("esports.voice.functions.{$function}") === true;
    }

    public static function moment(string $template): string
    {
        return self::MOMENTS[$template] ?? 'general';
    }

    /**
     * The post a template becomes: the voice's wording when it may speak and
     * passes, else the template's; mentions only of players who allow them.
     * Null only with `hold_template` when the spam gate turns the template
     * down as well (the caller offers another post instead).
     *
     * Options: `session` (the chat's stream session, for the mention pause),
     * `hold_template` (see above), `must_contain` (value keys the voice's
     * text must carry word for word).
     *
     * @param  array<string, string|int|null>  $values  the template's values, people as `nostr:npub1…`
     * @param  list<list<string>>  $tags  the post's tags (`p` tags of the people in the values among them)
     * @param  array{session?: string, hold_template?: bool, must_contain?: list<string>}  $options
     * @return array{content: string, tags: list<list<string>>, template_content: string, canon: ?string, form: ?string, voiced: bool}|null
     */
    public function compose(string $function, string $template, int $variant, array $values, array $tags, CarbonImmutable $now, array $options = []): ?array
    {
        $surface = self::FUNCTIONS[$function]['surface'] ?? null;
        $moment = self::moment($template);
        [$values, $tags, $names] = $this->mentionable($values, $tags, $surface, $moment, $now, $options['session'] ?? null);
        $templateContent = StreamBotCopy::render($template, $variant, $values);

        if ($surface !== null && ($options['hold_template'] ?? false) === true
            && $this->gate->textSkip($surface, $templateContent, $now, template: true) !== null) {
            return null;
        }

        $voiced = $this->enabled($function) ? $this->voiced($function, $moment, $values, $tags, $names, $templateContent, $now, $options) : null;

        return $voiced ?? ['content' => $templateContent, 'tags' => $tags, 'template_content' => $templateContent, 'canon' => null, 'form' => null, 'voiced' => false];
    }

    /**
     * Tells the spam gate about a post that went out (the text without what
     * follows its first blank line: a note's `nostr:naddr1…` and image).
     *
     * @param  list<list<string>>  $tags
     * @param  list<string>  $subjects
     */
    public function delivered(string $function, string $template, string $content, array $tags, CarbonImmutable $now, ?string $canon = null, ?string $form = null, array $subjects = [], ?string $session = null): void
    {
        $surface = self::FUNCTIONS[$function]['surface'] ?? null;

        if ($surface === null) {
            return;
        }

        $mentioned = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'p' && is_string($tag[1] ?? null)) {
                $mentioned[] = $tag[1];
            }
        }

        $this->gate->record($surface, explode("\n\n", $content, 2)[0], self::moment($template), $subjects, $now, $canon, $form, $mentioned, $session);
    }

    /**
     * A share post's sentence (the player's own note, in the player's
     * language): the voice's wording when the switch is on and it passes,
     * else the template's. The choice is kept for SHARE_TTL_SECONDS under
     * `$key`, so the note the page shows is the note the player signs and
     * the league checks (SignedEventGate compares them word for word).
     */
    public function sentence(string $type, string $sentence, string $language, string $key): string
    {
        if (! $this->enabled('share_post')) {
            return $sentence;
        }

        $cacheKey = 'voice:share:'.$language.':'.sha1($key.'|'.$sentence);
        $kept = Cache::get($cacheKey);

        if (is_string($kept) && $kept !== '') {
            return $kept;
        }

        $line = $this->voice->say(self::moment('share:'.$type), self::sentenceFacts($sentence), self::FUNCTIONS['share_post']['channel'], ['language' => $language]);
        $chosen = $line !== null && $line->tags === [] && ! str_contains($line->text, '#') ? $line->text : $sentence;

        Cache::put($cacheKey, $chosen, self::SHARE_TTL_SECONDS);

        return $chosen;
    }

    /**
     * The facts of a share sentence: the sentence itself, and each of its
     * names and numbers on its own, so the guard knows every one of them.
     *
     * @return array<string, mixed>
     */
    public static function sentenceFacts(string $sentence): array
    {
        preg_match_all('/[\p{Lu}\p{N}][\p{L}\p{N}.,:+\'’-]*/u', $sentence, $matches);

        return [
            'moment' => $sentence,
            'names_and_numbers' => array_values(array_unique(array_map(fn (string $word): string => rtrim($word, '.,:'), $matches[0]))),
        ];
    }

    /**
     * The voice's post, or null when it does not speak or does not pass.
     *
     * @param  array<string, string|int|null>  $values
     * @param  list<list<string>>  $tags
     * @param  array<string, string>  $names  pubkey => plain name of everyone the values still mention
     * @param  array{session?: string, hold_template?: bool, must_contain?: list<string>}  $options
     * @return array{content: string, tags: list<list<string>>, template_content: string, canon: ?string, form: ?string, voiced: bool}|null
     */
    private function voiced(string $function, string $moment, array $values, array $tags, array $names, string $templateContent, CarbonImmutable $now, array $options): ?array
    {
        $channel = self::FUNCTIONS[$function]['channel'];
        $surface = self::FUNCTIONS[$function]['surface'];
        $url = is_string($values['url'] ?? null) && $values['url'] !== '' ? $values['url'] : null;
        $maxChars = (int) config("esports.voice.channels.{$channel}.max_chars", 280) - ($url === null ? 0 : mb_strlen($url) + 3);

        [$facts, $people] = self::facts($values, $names);
        $facts['people'] = $people;

        $mustContain = [];
        foreach ($options['must_contain'] ?? [] as $key) {
            if (is_scalar($values[$key] ?? null) && (string) $values[$key] !== '') {
                $mustContain[] = (string) $values[$key];
            }
        }

        $line = $this->voice->say($moment, $facts, $channel, [
            'max_chars' => max(40, $maxChars),
            'instruction' => ($url === null ? 'Kein Link im Text.' : 'Kein Link im Text: die App hängt den Link aus den Fakten selbst an.')
                .($people === [] ? '' : ' Eine Person mit `mention` nennst du genau einmal, mit diesem Token (etwa {@winner}), nie zusätzlich mit ihrem Namen.'),
            'must_contain' => $mustContain,
        ]);

        if ($line === null) {
            return null;
        }

        $content = $url === null ? $line->text : $line->text."\n👉 ".$url;
        $otherTags = array_values(array_filter($tags, fn (array $tag): bool => ($tag[0] ?? null) !== 'p'));
        $postTags = [...$line->tags, ...$otherTags];

        if (StreamBotCopy::violations($content, $postTags) !== []) {
            return null;
        }

        if ($surface !== null && $this->gate->textSkip($surface, $content, $now, $line->canonId, $line->mood !== '' ? $line->mood : null) !== null) {
            return null;
        }

        return [
            'content' => $content,
            'tags' => $postTags,
            'template_content' => $templateContent,
            'canon' => $line->canonId,
            'form' => $line->mood !== '' ? $line->mood : null,
            'voiced' => true,
        ];
    }

    /**
     * The values with every mention a player does not allow (or the spam
     * gate's mention pause holds back) turned into the plain name, its `p`
     * tag dropped; and the names of the mentions that stay.
     *
     * @param  array<string, string|int|null>  $values
     * @param  list<list<string>>  $tags
     * @return array{0: array<string, string|int|null>, 1: list<list<string>>, 2: array<string, string>}
     */
    private function mentionable(array $values, array $tags, ?string $surface, string $moment, CarbonImmutable $now, ?string $session): array
    {
        $pubkeys = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'p' && is_string($tag[1] ?? null) && preg_match('/^[0-9a-f]{64}$/', $tag[1]) === 1) {
                $pubkeys[] = $tag[1];
            }
        }

        if ($pubkeys === []) {
            return [$values, $tags, []];
        }

        $users = User::query()->whereIn('pubkey', $pubkeys)->get()->keyBy('pubkey');
        $names = [];
        $dropped = [];

        foreach (array_unique($pubkeys) as $pubkey) {
            $user = $users->get($pubkey);
            $name = $user instanceof User ? StreamBotCopy::clean($user->displayName(), 40) : '';
            $allowed = (! $user instanceof User || $user->allowsMentions())
                && ($surface === null || $this->gate->mentionAllowed($surface, $pubkey, $moment, $now, $session));

            // Without a name to write in its place, a mention stays (only an account can switch it off).
            if ($allowed || $name === '') {
                $names[$pubkey] = $name === '' ? NostrKeys::hexToNpub($pubkey) : $name;

                continue;
            }

            $dropped[$pubkey] = $name;
        }

        if ($dropped === []) {
            return [$values, $tags, $names];
        }

        $replace = [];
        foreach ($dropped as $pubkey => $name) {
            $replace['nostr:'.NostrKeys::hexToNpub($pubkey)] = $name;
        }

        $values = array_map(fn (string|int|null $value): string|int|null => is_string($value) ? strtr($value, $replace) : $value, $values);
        $tags = array_values(array_filter($tags, fn (array $tag): bool => ! (($tag[0] ?? null) === 'p' && isset($dropped[$tag[1] ?? '']))));

        return [$values, $tags, $names];
    }

    /**
     * The model's facts from a template's values: no link, no empty value,
     * each mention as the plain name, and the people with a role each (the
     * value's key, numbered when one value names several).
     *
     * @param  array<string, string|int|null>  $values
     * @param  array<string, string>  $names  pubkey => plain name
     * @return array{0: array<string, string|int>, 1: list<array{role: string, name: string, pubkey: string, mentionable: bool}>}
     */
    public static function facts(array $values, array $names): array
    {
        $npubs = [];
        foreach ($names as $pubkey => $name) {
            $npubs['nostr:'.NostrKeys::hexToNpub($pubkey)] = ['pubkey' => $pubkey, 'name' => $name];
        }

        $facts = [];
        $people = [];
        $seen = [];

        foreach ($values as $key => $value) {
            if ($key === 'url' || $value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (is_string($value)) {
                $n = 0;

                foreach ($npubs as $npub => $person) {
                    if (! str_contains($value, $npub)) {
                        continue;
                    }

                    if (! isset($seen[$person['pubkey']])) {
                        $role = trim($value) === $npub ? $key : $key.'_'.(++$n);
                        $people[] = ['role' => strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', $role)), 'name' => $person['name'], 'pubkey' => $person['pubkey'], 'mentionable' => true];
                        $seen[$person['pubkey']] = true;
                    }

                    $value = str_replace($npub, $person['name'], $value);
                }

                $value = trim($value, " \t\n·,");
            }

            $facts[$key] = $value;
        }

        return [$facts, $people];
    }
}
