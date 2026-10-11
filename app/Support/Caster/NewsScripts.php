<?php

namespace App\Support\Caster;

use App\Models\CasterNews;
use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The news segment (user 2026-10-11: "Breaking Bitcoin News kommentieren und
 * auch Schlagzeilen aus Deutschland … gerne viel Sarkasmus und Häme"): an
 * intro for the section, the headline word for word inside a full sentence
 * that names its source ("Im Nachrichtenfeed von tagesschau steht gerade
 * diese Schlagzeile: …", headlineLine(); user 2026-10-12: a bare title "wird
 * zu schlecht tonalisiert, also komplette Sätze ab jetzt nur noch"), then
 * Markus' comment. The sections take turns; the
 * newest fresh headline of the section comes first, and each headline is
 * used once, whatever became of it.
 *
 * The segment is the league voice's (CasterVoice::news(), one model call per
 * headline: the headline is marked used before the call): its own lead-in,
 * the headline word for word, its free comment. When the model
 * calls the headline unfit (SKIP), the segment is dropped; when the voice is
 * off, capped, failed or its draft was turned down, a pattern reaction from
 * resources/data/voice/news.json stands in (opinion only, fits any headline
 * of its section).
 *
 * A long segment (LengthMix; user 2026-10-11: "… auch an die News, die
 * länger sein dürfen"): the same headline with a comment of
 * `length.news_long` sentences, still the one model call per headline and
 * still opinion only on top of the headline's facts; the pattern stand-in
 * chains LONG_REACTIONS reactions of the section.
 */
class NewsScripts
{
    /** Pattern reactions (at least 15 per section) not repeated within this many news segments: they rotate. */
    private const REACTION_MEMORY = 12;

    /** Pattern reactions a long segment's stand-in chains. */
    private const LONG_REACTIONS = 3;

    public function __construct(private CasterVoice $voice) {}

    /**
     * The next headline: the section that did not speak last first, the newest
     * unused one published within `fresh_hours`. `$section` first (a chat
     * wish for Bitcoin or German news, ChatWishes), the other one after it.
     */
    public function next(CarbonImmutable $now, ?string $section = null): ?CasterNews
    {
        $last = CasterScript::query()->where('kind', CasterScript::NEWS)->orderByDesc('id')->value('topic');
        $sections = $last === CasterNews::BITCOIN ? [CasterNews::GERMANY, CasterNews::BITCOIN] : [CasterNews::BITCOIN, CasterNews::GERMANY];
        if ($section !== null && in_array($section, $sections, true)) {
            $sections = [$section, ...array_values(array_diff($sections, [$section]))];
        }
        $fresh = $now->subHours((int) config('tv.commentary.news.fresh_hours', 12));

        foreach ($sections as $section) {
            $news = CasterNews::query()->where('section', $section)->whereNull('used_at')->whereNull('skipped')
                ->where('published_at', '>=', $fresh)->orderByDesc('published_at')->orderByDesc('id')->first();

            if ($news !== null) {
                return $news;
            }
        }

        return null;
    }

    /**
     * The segment for a headline, or null when it must not be spoken (the
     * filter or the model turned it down). The headline is used either way.
     * `$long`: the long variant (a comment of `length.news_long` sentences).
     *
     * @return array{text: string, source: string, long: bool}|null
     */
    public function write(CasterNews $news, CarbonImmutable $now, bool $ai, bool $long = false): ?array
    {
        $news->update(['used_at' => $now]);

        // The filter ran when the headline was stored; it runs again here, so a widened word list holds for stored rows.
        $reason = NewsFilter::reason($news->title, $news->language);
        if ($reason !== null) {
            $news->update(['skipped' => $reason]);

            return null;
        }

        $comment = null;
        $source = CasterScript::SOURCE_PATTERN;

        if ($ai) {
            // The source as context (user 2026-10-12: "die Source als Kontext nutzen"): feed, link domain, publication time.
            $context = array_filter([
                'Feed' => (string) $news->feed,
                'Link-Domain' => (string) parse_url((string) $news->link, PHP_URL_HOST),
                'Veröffentlicht' => $news->published_at->timezone('Europe/Berlin')->format('d.m.Y H:i'),
            ]);
            $answer = $this->voice->news($news->title, $news->source, $news->section, $long, $context);

            if ($answer !== null && $answer['skip']) {
                $news->update(['skipped' => 'model']);

                return null;
            }

            if ($answer !== null && $answer['text'] !== null) {
                $comment = $answer['text'];
                $source = CasterScript::SOURCE_AI;
            }
        }

        // The model's segment stands alone: its own lead-in, the headline word for word, its comment (user
        // 2026-10-12: the fixed intros and reactions repeated). The pools below are the fallback only.
        if ($comment !== null) {
            return ['text' => $comment, 'source' => $source, 'long' => $long];
        }

        if (! PatternScripts::enabled()) {
            return null;
        }

        $data = self::data();
        $intros = $data['intros'][$news->section] ?? $data['intros'][CasterNews::GERMANY];

        return [
            'text' => implode("\n", [
                $intros[$news->id % count($intros)],
                self::headlineLine(CasterVoice::withoutHashtags($news->title), $news->source, (int) $news->id, $data['headline_carriers']),
                implode("\n", array_map(fn (int $turn): string => $this->reaction($news->section, $data), range(1, $long ? self::LONG_REACTIONS : 1))),
            ]),
            'source' => $source,
            'long' => $long,
        ];
    }

    /**
     * The headline word for word at the end of a carrier sentence naming its
     * source (`headline_carriers`, by `$turn`); the shortest carrier when the
     * picked one would push the sentence over `max_words_per_sentence`.
     *
     * @param  list<string>  $carriers  each with {source} and {headline}
     */
    public static function headlineLine(string $headline, string $source, int $turn, array $carriers): string
    {
        $fill = fn (string $carrier): string => str_replace(['{source}', '{headline}'], [$source, self::asSentence($headline)], $carrier);
        $line = $fill($carriers[$turn % count($carriers)]);

        if (count(preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: []) > (int) config('tv.commentary.scripts.max_words_per_sentence', 32)) {
            usort($carriers, fn (string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));
            $line = $fill($carriers[0]);
        }

        return $line;
    }

    /** A headline as one spoken sentence: a final full stop when it has no closing mark. */
    public static function asSentence(string $headline): string
    {
        $headline = trim($headline);

        return preg_match('/[.!?…]["“”»«\']?$/u', $headline) === 1 ? $headline : $headline.'.';
    }

    /**
     * A pattern reaction of the section, none of the last REACTION_MEMORY again.
     *
     * @param  array{reactions: array<string, list<string>>}  $data
     */
    private function reaction(string $section, array $data): string
    {
        $lines = $data['reactions'][$section] ?? $data['reactions'][CasterNews::GERMANY];
        $recent = Cache::get('caster:news:reactions');
        $recent = is_array($recent) ? $recent : [];
        $fresh = array_values(array_filter($lines, fn (string $line): bool => ! in_array($line, $recent, true)));
        $pick = ($fresh === [] ? $lines : $fresh)[0];

        Cache::put('caster:news:reactions', array_slice([$pick, ...$recent], 0, self::REACTION_MEMORY), now()->addDay());

        return $pick;
    }

    /**
     * @return array{intros: array<string, list<string>>, headline_carriers: list<string>, reactions: array<string, list<string>>}
     */
    public static function data(): array
    {
        /** @var array{intros: array<string, list<string>>, headline_carriers: list<string>, reactions: array<string, list<string>>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/news.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
