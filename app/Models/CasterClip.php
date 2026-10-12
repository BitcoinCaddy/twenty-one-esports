<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A voiced script in the server's audio spool (App\Support\Caster\ClipSpool):
 * an Opus file on the spool disk, deleted with its row right after it played
 * or when it expired. No audio in the database.
 *
 * @property int $id
 * @property int $caster_script_id
 * @property string $phase
 * @property string $kind
 * @property string $path relative to the spool disk
 * @property float $duration_seconds
 * @property int $bytes
 * @property list<float> $sentence_ends seconds into the clip where each sentence ends
 * @property string $status ready or playing
 * @property array{dressed?: bool, scripts?: list<int>, boundaries?: list<float>, protected_seconds?: float}|null $block a programme block rendered on the workstation (plan "TWENTY ONE Radio", P6): the scripts it covers (the first is `caster_script_id`), its segment boundaries in seconds, the opening stretch nothing cuts into, and that it carries its own station sound; null for a plain clip
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['caster_script_id', 'phase', 'kind', 'path', 'duration_seconds', 'bytes', 'sentence_ends', 'status', 'expires_at', 'block'])]
class CasterClip extends Model
{
    public const READY = 'ready';

    public const PLAYING = 'playing';

    /** The kind of a programme block that no slot of the broadcast clock anchors (a talk block). */
    public const BLOCK = 'block';

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'bytes' => 'integer',
            'sentence_ends' => 'array',
            'expires_at' => 'datetime',
            'block' => 'array',
        ];
    }

    /** Whether this is a programme block rendered on the workstation. */
    public function isBlock(): bool
    {
        return is_array($this->block);
    }

    /** Whether the clip carries its own station sound already (a rendered block): the stream must not dress it again. */
    public function isDressed(): bool
    {
        return is_array($this->block) && ($this->block['dressed'] ?? false) === true;
    }

    /**
     * The segment boundaries of a block, seconds into the clip, ascending: where a reaction may cut in.
     *
     * @return list<float>
     */
    public function boundaries(): array
    {
        $boundaries = is_array($this->block) ? array_map('floatval', array_values(array_filter((array) ($this->block['boundaries'] ?? []), 'is_numeric'))) : [];
        sort($boundaries);

        return $boundaries;
    }

    /** Seconds at the start of a block nothing cuts into (the news of a news block); 0 for any other clip. */
    public function protectedSeconds(): float
    {
        return is_array($this->block) ? max(0.0, (float) ($this->block['protected_seconds'] ?? 0)) : 0.0;
    }

    /**
     * @return BelongsTo<CasterScript, $this>
     */
    public function script(): BelongsTo
    {
        return $this->belongsTo(CasterScript::class, 'caster_script_id');
    }
}
