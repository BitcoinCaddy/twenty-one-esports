<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pubkey's level in the web of trust of the league's trust anchors
 * (App\Support\Caster\WebOfTrust): 1 = an anchor follows it, 2 = at least
 * two level-1 accounts follow it. Recomputed daily as a whole.
 *
 * @property string $pubkey
 * @property int $level
 * @property Carbon $computed_at
 */
#[Fillable(['pubkey', 'level', 'computed_at'])]
class WotLevel extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'pubkey';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['level' => 'integer', 'computed_at' => 'datetime'];
    }
}
