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
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['caster_script_id', 'phase', 'kind', 'path', 'duration_seconds', 'bytes', 'sentence_ends', 'status', 'expires_at'])]
class CasterClip extends Model
{
    public const READY = 'ready';

    public const PLAYING = 'playing';

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'bytes' => 'integer',
            'sentence_ends' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CasterScript, $this>
     */
    public function script(): BelongsTo
    {
        return $this->belongsTo(CasterScript::class, 'caster_script_id');
    }
}
