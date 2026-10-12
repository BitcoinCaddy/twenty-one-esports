<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A scene script of the TWENTY ONE TV commentary (App\Support\Caster\ScriptQueue):
 * one sentence per line, written ahead by the league voice, voiced on the
 * workstation and spooled as a CasterClip.
 *
 * @property int $id
 * @property string $kind scene, live, chat, filler, news, history, followed or bulletin
 * @property string $phase App\Support\Caster\ScenePhase
 * @property string|null $scene the rotation scene it was written for, when one (a champion slide)
 * @property int $priority
 * @property string $text
 * @property string $facts_hash
 * @property string $status queued, claimed, voiced, expired
 * @property string $source ai (the league voice) or pattern (App\Support\Caster\PatternScripts)
 * @property string|null $topic App\Support\Caster\SceneTopic for a scene script; App\Support\Caster\ChatReactions::ZAP_TOPIC or GREETING_TOPIC for a zap thank-you or a greeting; the news section (bitcoin, germany) for a news segment
 * @property float $estimated_seconds
 * @property string|null $part_group the parts of one long script share it (App\Support\Caster\ScriptQueue::enqueue()); null for a script in one piece
 * @property int|null $part_index the position of the part in its group, from 1
 * @property list<string>|null $speakers the speaker of each line of a duet (App\Support\Caster\DuetDirector::MARKUS, ::SIRIUS), aligned with sentences(); null when Markus speaks alone
 * @property Carbon|null $airs_at a bulletin's or rubric's slot, a teaser's first moment (App\Support\Caster\Programme\BroadcastClock): it plays from then on, never before; null for every other script
 * @property Carbon|null $claimed_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kind', 'phase', 'scene', 'priority', 'text', 'facts_hash', 'status', 'source', 'topic', 'estimated_seconds', 'claimed_at', 'expires_at', 'part_group', 'part_index', 'speakers', 'airs_at'])]
class CasterScript extends Model
{
    public const QUEUED = 'queued';

    public const CLAIMED = 'claimed';

    public const VOICED = 'voiced';

    public const EXPIRED = 'expired';

    public const SCENE = 'scene';

    public const LIVE = 'live';

    public const CHAT = 'chat';

    public const FILLER = 'filler';

    /** A news segment: a headline with its source and Markus' comment (App\Support\Caster\NewsScripts). */
    public const NEWS = 'news';

    /** A history segment: "Markus erzählt vom Bauernkrieg" (App\Support\Caster\HistoryScripts). */
    public const HISTORY = 'history';

    /** A followed-profile segment: Markus on a Nostr note of a profile he follows (App\Support\Caster\FollowedScripts). */
    public const FOLLOWED = 'followed';

    /** A news bulletin at :00 or :30 (App\Support\Caster\Programme\NewsBulletin): voiced ahead, played at its slot (`airs_at`). */
    public const BULLETIN = 'bulletin';

    /** A rubric at a rubric slot of the clock (App\Support\Caster\Programme\RubricScripts): voiced ahead, played from its slot on (`airs_at`). */
    public const RUBRIC = 'rubric';

    /** A programme teaser before a slot (App\Support\Caster\Programme\Announcements): played at a clip boundary from `airs_at` until it expires. */
    public const TEASER = 'teaser';

    /** The kinds the broadcast clock places (`airs_at`): never counted as airtime ahead, never refused by the airtime cap. */
    public const PROGRAMME = [self::BULLETIN, self::RUBRIC, self::TEASER];

    /** The long segments that play on calm slots only, one at a time (App\Support\Caster\ClipSpool::next()). */
    public const SEGMENTS = [self::NEWS, self::HISTORY, self::FOLLOWED];

    public const SOURCE_AI = 'ai';

    public const SOURCE_PATTERN = 'pattern';

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'part_index' => 'integer',
            'estimated_seconds' => 'float',
            'claimed_at' => 'datetime',
            'airs_at' => 'datetime',
            'expires_at' => 'datetime',
            'speakers' => 'array',
        ];
    }

    /**
     * @return HasMany<CasterClip, $this>
     */
    public function clips(): HasMany
    {
        return $this->hasMany(CasterClip::class);
    }

    /**
     * One row per script: a script in one piece, or the first part of a long one (its later parts left out).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whole(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('part_index')->orWhere('part_index', 1));
    }

    /** Whether this is a later part of a long script (part 2 on), which plays only right after the part before it. */
    public function isContinuation(): bool
    {
        return $this->part_group !== null && (int) $this->part_index > 1;
    }

    /**
     * The speaker of each of sentences() for a duet (DuetDirector), null for Markus alone or when the two do not line up.
     *
     * @return list<string>|null
     */
    public function lineSpeakers(): ?array
    {
        $speakers = is_array($this->speakers) ? array_values(array_filter($this->speakers, 'is_string')) : null;

        return $speakers !== null && count($speakers) === count($this->sentences()) ? $speakers : null;
    }

    /**
     * @return list<string>
     */
    public function sentences(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->text)), fn (string $line): bool => $line !== ''));
    }
}
