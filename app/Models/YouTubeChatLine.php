<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One text message of the YouTube restream's live chat
 * (App\Support\Caster\YouTubeChat): the speakable display name, the
 * sanitised text and its time; never the author's channel id. Kept
 * `twentyone.stream.youtube.chat.retention_hours` (caster:prune).
 *
 * @property int $id
 * @property string $message_id
 * @property string|null $author
 * @property string $message
 * @property int $said_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['message_id', 'author', 'message', 'said_at'])]
class YouTubeChatLine extends Model
{
    protected $table = 'youtube_chat_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['said_at' => 'integer'];
    }
}
