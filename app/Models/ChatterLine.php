<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One trusted chat message the caster saw and the start of Markus' reply to
 * it: the rolling 24-hour chat memory (App\Support\Caster\ChatMemory).
 *
 * @property int $id
 * @property string $pubkey
 * @property string|null $name
 * @property string $message
 * @property string|null $reply
 * @property int $said_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'name', 'message', 'reply', 'said_at'])]
class ChatterLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['said_at' => 'integer'];
    }
}
