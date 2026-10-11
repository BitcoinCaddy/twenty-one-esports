<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A trusted stream chatter as the commentary knows them
 * (App\Support\Caster\ChatterCache): the public kind 0 snapshot, a persona
 * card of keywords (never raw notes) and when they last chatted. Never shown
 * publicly; removed with their notes and exchanges after
 * `tv.commentary.chat.inactive_days` without chat.
 *
 * @property int $id
 * @property string $pubkey
 * @property string|null $name
 * @property string|null $about
 * @property int|null $profile_created_at
 * @property int|null $newest_note_at
 * @property string|null $persona
 * @property Carbon|null $persona_at
 * @property Carbon|null $fetched_at
 * @property Carbon|null $last_chat_at
 * @property Carbon|null $last_reaction_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'name', 'about', 'profile_created_at', 'newest_note_at', 'persona', 'persona_at', 'fetched_at', 'last_chat_at', 'last_reaction_at'])]
class ChatterProfile extends Model
{
    protected function casts(): array
    {
        return [
            'profile_created_at' => 'integer',
            'newest_note_at' => 'integer',
            'persona_at' => 'datetime',
            'fetched_at' => 'datetime',
            'last_chat_at' => 'datetime',
            'last_reaction_at' => 'datetime',
        ];
    }
}
