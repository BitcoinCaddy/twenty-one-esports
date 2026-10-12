<?php

namespace App\Support\Caster;

use App\Models\CasterFollowedNote;
use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The followed-profile segment (user 2026-10-11: "Für mehr Varianz kann er
 * noch auf die Nostr-Posts von nprofile1… reagieren, aber pro Post nur
 * einmal"): an intro naming the profile, then Markus retells the note in
 * his own words and reacts to it. The notes come in time order, oldest
 * first, a note younger than `jump_ahead_minutes` ahead of the backlog
 * (next()); an older one is announced with its month and year (when()).
 * Each note is used once, whatever became of it: it is marked reacted
 * before the model is asked.
 *
 * The take is the league voice's (CasterVoice::followedNote(), one model call
 * per note plus its fact check). When the model calls the note unfit (SKIP),
 * the segment is dropped; when the voice is off, capped, failed or its draft
 * was turned down, the pattern stand-in reads a short quote of the note
 * ("Auf Nostr schreibt …: …") and a reaction from
 * resources/data/voice/followed.json. A note that does not read as German
 * gets no stand-in (the German voice mangles a foreign quote): it is dropped.
 *
 * The profile Markus named is his own (kind 0 "markusturm", read 2026-10-11):
 * a profile whose display name is the caster's own name (SELF_NAME, spaces
 * and case aside) is announced and retold in the first person ("Ich hab auf
 * Nostr …").
 */
class FollowedScripts
{
    /** Pattern reactions not repeated within this many followed-profile segments: they rotate. */
    private const REACTION_MEMORY = 12;

    /** The caster's own name: a followed profile of this name is Markus himself. */
    public const SELF_NAME = 'Markus Turm';

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(private CasterVoice $voice) {}

    /**
     * The next unused note of a profile still followed: the newest one
     * younger than `followed.jump_ahead_minutes` jumps ahead, else the oldest
     * (user 2026-10-12: "… aber sortiert nach Zeit": a journey through the
     * history from the first note on). With a `followed.fresh_hours` limit,
     * only notes within it.
     */
    public function next(CarbonImmutable $now): ?CasterFollowedNote
    {
        $pubkeys = FollowedNotes::pubkeys();

        if ($pubkeys === []) {
            return null;
        }

        $fresh = (int) config('tv.commentary.followed.fresh_hours', 0);
        $unused = fn () => CasterFollowedNote::query()->whereIn('pubkey', $pubkeys)->whereNull('reacted_at')->whereNull('skipped')
            ->when($fresh > 0, fn ($query) => $query->where('note_created_at', '>=', $now->subHours($fresh)));

        return $unused()->where('note_created_at', '>=', $now->subMinutes((int) config('tv.commentary.followed.jump_ahead_minutes', 60)))
            ->orderByDesc('note_created_at')->orderByDesc('id')->first()
            ?? $unused()->orderBy('note_created_at')->orderBy('id')->first();
    }

    /**
     * "Im März zweitausenddreiundzwanzig" for a note older than
     * `followed.dated_after_hours` (month and year in words, the app's time
     * zone), null for a recent one.
     */
    public static function when(CasterFollowedNote $note, CarbonImmutable $now): ?string
    {
        $created = CarbonImmutable::instance($note->note_created_at)->setTimezone((string) config('app.timezone'));

        if ($created->greaterThan($now->subHours((int) config('tv.commentary.followed.dated_after_hours', 48)))) {
            return null;
        }

        return 'Im '.self::MONTHS[$created->month - 1].' '.ReactionLines::spokenNumber($created->year);
    }

    /**
     * The segment for a note, or null when it must not be spoken (the
     * filters or the model turned it down). The note is used either way.
     *
     * @return array{text: string, source: string}|null
     */
    public function write(CasterFollowedNote $note, CarbonImmutable $now, bool $ai): ?array
    {
        $note->update(['reacted_at' => $now]);

        // The filters ran when the note was stored; they run again here, so a widened word list holds for stored rows.
        $reason = FollowedNotes::isExplicit($note->content) ? FollowedNotes::NSFW : NewsFilter::unfit($note->content);
        if ($reason !== null) {
            $note->update(['skipped' => $reason]);

            return null;
        }

        $name = FollowedNotes::displayName($note->pubkey);
        $self = self::isSelf($name);
        $german = NewsFilter::readsGerman($note->content);
        $data = self::data();
        $when = self::when($note, $now);
        $fill = fn (string $line): string => str_replace(['{name}', '{when}'], [$name, (string) $when], $line);

        if ($ai) {
            $answer = $this->voice->followedNote($note->content, $name, $german, $self, $when);

            if ($answer !== null && $answer['skip']) {
                $note->update(['skipped' => 'model']);

                return null;
            }

            if ($answer !== null && $answer['text'] !== null) {
                $intros = $data[($self ? 'self_' : '').($when === null ? 'intros' : 'dated_intros')];

                return ['text' => $fill($intros[$note->id % count($intros)])."\n".$answer['text'], 'source' => CasterScript::SOURCE_AI];
            }
        }

        if (! $german) {
            $note->update(['skipped' => NewsFilter::FOREIGN]);

            return null;
        }

        return [
            'text' => implode("\n", [
                $fill($data[($self ? 'self_' : '').($when === null ? 'attribution' : 'dated_attribution')]).' '.self::quote($note->content, (int) config('tv.commentary.followed.quote_words', 20)),
                $this->reaction($data),
            ]),
            'source' => CasterScript::SOURCE_PATTERN,
        ];
    }

    /**
     * One of Markus' own stored notes, drawn at random, as material for a
     * scene (CasterVoice::MATERIALS `nostr`): when he wrote it and its first
     * forty words; none that the filters turned down, null when there is none.
     */
    public static function ownNote(CarbonImmutable $now): ?string
    {
        $own = array_values(array_filter(FollowedNotes::pubkeys(), fn (string $pubkey): bool => self::isSelf(FollowedNotes::displayName($pubkey))));
        $note = $own === [] ? null : CasterFollowedNote::query()->whereIn('pubkey', $own)->whereNull('skipped')
            ->inRandomOrder()->first();

        if ($note === null || FollowedNotes::isExplicit($note->content) || NewsFilter::unfit($note->content) !== null) {
            return null;
        }

        return (self::when($note, $now) ?? 'Vor kurzem').' hast du auf Nostr geschrieben: „'.ChatMemory::firstWords($note->content, 40).'“';
    }

    /** Whether a display name is the caster's own ("markusturm", "Markus Turm"). */
    public static function isSelf(string $name): bool
    {
        return self::compactName($name) === self::compactName(self::SELF_NAME);
    }

    /** A name for comparing: lower case, letters and digits only ("Markus Turm" and "markusturm" are the same). */
    public static function compactName(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }

    /**
     * The first `$words` words of a note as one spoken sentence: cut after
     * the last full sentence within them, or with an ellipsis mid-sentence.
     */
    public static function quote(string $text, int $words = 20): string
    {
        $all = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_slice($all, 0, max(1, $words));

        if (count($kept) === count($all)) {
            return NewsScripts::asSentence(implode(' ', $kept));
        }

        for ($i = count($kept) - 1; $i >= 2; $i--) {
            if (preg_match('/[.!?…]["“”»«\']?$/u', $kept[$i]) === 1) {
                return implode(' ', array_slice($kept, 0, $i + 1));
            }
        }

        return rtrim(implode(' ', $kept), ' ,;:-–').'…';
    }

    /**
     * A pattern reaction, none of the last REACTION_MEMORY again.
     *
     * @param  array{reactions: list<string>}  $data
     */
    private function reaction(array $data): string
    {
        $recent = Cache::get('caster:followed:reactions');
        $recent = is_array($recent) ? $recent : [];
        $fresh = array_values(array_filter($data['reactions'], fn (string $line): bool => ! in_array($line, $recent, true)));
        $pick = ($fresh === [] ? $data['reactions'] : $fresh)[0];

        Cache::put('caster:followed:reactions', array_slice([$pick, ...$recent], 0, self::REACTION_MEMORY), now()->addDay());

        return $pick;
    }

    /**
     * @return array{intros: list<string>, self_intros: list<string>, dated_intros: list<string>, self_dated_intros: list<string>, attribution: string, self_attribution: string, dated_attribution: string, self_dated_attribution: string, reactions: list<string>}
     */
    public static function data(): array
    {
        /** @var array{intros: list<string>, self_intros: list<string>, dated_intros: list<string>, self_dated_intros: list<string>, attribution: string, self_attribution: string, dated_attribution: string, self_dated_attribution: string, reactions: list<string>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/followed.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
