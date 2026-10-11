<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A kind 1 note of a profile the TWENTY ONE TV commentary follows
 * (App\Support\Caster\FollowedNotes, App\Support\Caster\FollowedScripts):
 * reacted to at most once; with a `tv.commentary.followed.fresh_hours` limit
 * deleted after `keep_days`, without one (the default) kept for good.
 *
 * @property int $id
 * @property string $event_id the note's id (hex), unique: a note is stored once
 * @property string $pubkey its author (hex)
 * @property Carbon $note_created_at
 * @property string $content the note as plain text (links, nostr references and markup out)
 * @property Carbon|null $reacted_at spoken, or turned down (`skipped` says why)
 * @property string|null $skipped
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['event_id', 'pubkey', 'note_created_at', 'content', 'reacted_at', 'skipped'])]
class CasterFollowedNote extends Model
{
    protected function casts(): array
    {
        return [
            'note_created_at' => 'datetime',
            'reacted_at' => 'datetime',
        ];
    }
}
