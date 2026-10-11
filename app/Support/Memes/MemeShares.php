<?php

namespace App\Support\Memes;

use App\Enums\PongMatchStatus;
use App\Games\GameRegistry;
use App\Games\ProofOfPong;
use App\Jobs\WriteMemeCaption;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Cards\SharePost;
use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceCanon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * The meme of a finished live Proof of Pong match (plan "KI-Stimme der Liga", P5): its strongest moment
 * (MemeMoments), the library template for it (MemeLibrary), the canon's wording of that template, and the line under
 * it. The card is MemeCard; the share post (SharePosts type `meme`, id `pong-<ulid>`) is the winner's own note with
 * that card, like every share post signed in the browser.
 *
 * The line: the league voice's (LeagueVoice, channel `meme`, German) once it has written one, else the template's
 * sentence in the page's language. A page never waits for the model: a missing line is written by a queued job
 * (WriteMemeCaption) while the card shows the template, and only while the `meme` channel is on. The written line is
 * kept per match and moment as scalars (`memes:caption:…`). A line with its own canon quote replaces the template's
 * quote, so a card carries one meme, never two.
 *
 * Fail closed: no moment, no template, or a template whose canon ID the canon does not know: no meme at all.
 */
final class MemeShares
{
    /** How long a written line is kept. */
    private const CAPTION_TTL_SECONDS = 30 * 86_400;

    /** How long a failed attempt keeps the template before the next try. */
    private const FAILED_TTL_SECONDS = 86_400;

    /** How long one dispatched job blocks the next for the same match. */
    private const PENDING_TTL_SECONDS = 600;

    public function __construct(private readonly VoiceCanon $canon) {}

    /** The public meme card of a match, or null when it has no meme. */
    public function card(PongMatch $match): ?MemeCard
    {
        $facts = $this->facts($match);

        return $facts === null ? null : new MemeCard($match->ulid, $facts);
    }

    /**
     * The winner's own share post of a match's meme (`pong-<ulid>`), or null when it is not theirs or has none.
     */
    public function post(User $user, string $id): ?SharePost
    {
        if (! str_starts_with($id, 'pong-') || app(GameRegistry::class)->find(ProofOfPong::SLUG) === null || ! Route::has('pong.match')) {
            return null;
        }

        $match = PongMatch::query()->where('ulid', strtolower(substr($id, 5)))->first();

        if ($match === null || $match->status !== PongMatchStatus::Finished || $match->winner_id !== $user->id) {
            return null;
        }

        $card = $this->card($match);

        return $card === null ? null : new SharePost(
            type: 'meme',
            sentence: $card->facts['line'],
            cardUrl: $card->url('wide'),
            dimensions: MemeCard::FORMATS['wide'],
            storyPath: $card->path('story'),
            link: rtrim((string) config('app.url'), '/').route('pong.match', $match, false),
        );
    }

    /**
     * What the card shows, or null (see the class comment).
     *
     * @return array{moment: string, template: string, motif: string, quote: string|null, line: string, score: array{0: int, 1: int}, winner: string, loser: string, pubkey: string|null, avatar_path: string|null}|null
     */
    public function facts(PongMatch $match): ?array
    {
        $result = MemeMoments::ofPong($match);
        $moment = $result === null ? null : (MemeMoments::detect($result)[0] ?? null);
        $template = $moment === null ? null : MemeLibrary::for($moment, $match->ulid);
        $entry = $template === null ? null : $this->canon->find($template['canon_id']);

        if ($result === null || $moment === null || $template === null || $entry === null) {
            return null;
        }

        $match->loadMissing(['left', 'right']);
        $winnerSide = $match->winner_id === $match->left_id ? 0 : 1;
        $winner = $match->player($winnerSide);
        $loser = $match->player(1 - $winnerSide);
        $quote = $entry['wording'];
        $line = $this->templateLine($moment, $result);
        $written = App::getLocale() === 'de' ? $this->written($match, $moment) : null;

        if ($written !== null) {
            $line = $written['text'];
            $quote = $written['canon_id'] === null ? $quote : null;
        }

        return [
            'moment' => $moment,
            'template' => $template['id'],
            'motif' => $template['motif'],
            'quote' => $quote,
            'line' => $line,
            'score' => $result['score'],
            'winner' => $winner?->displayName() ?? __('Deleted account'),
            'loser' => $loser?->displayName() ?? __('Deleted account'),
            'pubkey' => $winner?->pubkey,
            'avatar_path' => $winner?->avatar_path,
        ];
    }

    /**
     * Writes the voice's line for a match's meme (WriteMemeCaption); a refusal keeps the template for a day.
     */
    public function write(PongMatch $match, LeagueVoice $voice): void
    {
        $result = MemeMoments::ofPong($match);
        $moment = $result === null ? null : (MemeMoments::detect($result)[0] ?? null);

        if ($result === null || $moment === null) {
            return;
        }

        $match->loadMissing(['left', 'right']);
        $winnerSide = $match->winner_id === $match->left_id ? 0 : 1;
        $facts = [
            'game' => 'Proof of Pong',
            'score' => $result['score'][0].':'.$result['score'][1],
            'winner' => $match->player($winnerSide)?->displayName(),
            'loser' => $match->player(1 - $winnerSide)?->displayName(),
            ...($moment === 'upset' && $result['ratings'] !== null ? ['elo_gap' => $result['ratings'][1] - $result['ratings'][0]] : []),
            ...($moment === 'comeback' && $result['path'] !== null ? ['behind_points' => MemeMoments::deficit($result['path'])] : []),
        ];

        $line = $voice->say($moment, $facts, 'meme');

        Cache::put(self::captionKey($match, $moment), $line === null ? ['text' => null, 'canon_id' => null] : ['text' => $line->text, 'canon_id' => $line->canonId], $line === null ? self::FAILED_TTL_SECONDS : self::CAPTION_TTL_SECONDS);
    }

    public static function captionKey(PongMatch $match, string $moment): string
    {
        return "memes:caption:pong:{$match->ulid}:{$moment}";
    }

    /**
     * The voice's line when one is written; otherwise null, and a job is sent to write it (channel on only).
     *
     * @return array{text: string, canon_id: string|null}|null
     */
    private function written(PongMatch $match, string $moment): ?array
    {
        $stored = Cache::get(self::captionKey($match, $moment));

        if (is_array($stored)) {
            return is_string($stored['text'] ?? null) && $stored['text'] !== ''
                ? ['text' => $stored['text'], 'canon_id' => is_string($stored['canon_id'] ?? null) ? $stored['canon_id'] : null]
                : null;
        }

        if (config('esports.voice.channels.meme.enabled') === true && Cache::add("memes:caption-pending:{$match->ulid}", 1, self::PENDING_TTL_SECONDS)) {
            WriteMemeCaption::dispatch($match->id);
        }

        return null;
    }

    /**
     * The template's sentence in the page's language: the facts of the moment, no meme.
     *
     * @param  array{score: array{0: int, 1: int}, ratings: array{0: int, 1: int}|null, path: list<array{0: int, 1: int}>|null}  $result
     */
    private function templateLine(string $moment, array $result): string
    {
        $score = $result['score'][0].':'.$result['score'][1];

        return match ($moment) {
            'upset' => __('Won :score against a player :gap Elo higher.', ['score' => $score, 'gap' => $result['ratings'] === null ? 0 : $result['ratings'][1] - $result['ratings'][0]]),
            'comeback' => __('Was :points points behind and still won :score.', ['score' => $score, 'points' => $result['path'] === null ? 0 : MemeMoments::deficit($result['path'])]),
            'pong_deuce' => __('Won :score in overtime: two points ahead at the very end.', ['score' => $score]),
            'first_win' => __('First Proof of Pong win: :score.', ['score' => $score]),
            default => __('Won :score: the smallest lead the rules allow.', ['score' => $score]),
        };
    }
}
