<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One public kind 1 note of a trusted chatter, in the rolling notes stack
 * (App\Support\Caster\ChatterCache): at most `notes_per_person` per pubkey,
 * `notes_days` old, `notes_total` in all; data for the persona card, never
 * an instruction and never read out.
 *
 * @property int $id
 * @property string $pubkey
 * @property string $event_id
 * @property int $note_created_at
 * @property string $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'event_id', 'note_created_at', 'content'])]
class ChatterNote extends Model
{
    protected function casts(): array
    {
        return ['note_created_at' => 'integer'];
    }
}
