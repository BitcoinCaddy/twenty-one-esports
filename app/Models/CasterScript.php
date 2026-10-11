<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A scene script of the TWENTY ONE TV commentary (App\Support\Caster\ScriptQueue):
 * one sentence per line, written ahead by the league voice, voiced on the
 * workstation and spooled as a CasterClip.
 *
 * @property int $id
 * @property string $kind scene, live, chat or filler
 * @property string $phase App\Support\Caster\ScenePhase
 * @property string|null $scene the rotation scene it was written for, when one (a champion slide)
 * @property int $priority
 * @property string $text
 * @property string $facts_hash
 * @property string $status queued, claimed, voiced, expired
 * @property float $estimated_seconds
 * @property Carbon|null $claimed_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kind', 'phase', 'scene', 'priority', 'text', 'facts_hash', 'status', 'estimated_seconds', 'claimed_at', 'expires_at'])]
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

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'estimated_seconds' => 'float',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return list<string>
     */
    public function sentences(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->text)), fn (string $line): bool => $line !== ''));
    }
}
