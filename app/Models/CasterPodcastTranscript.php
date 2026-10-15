<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The latest EINUNDZWANZIG podcast episode's transcript (App\Support\Caster\PodcastTranscripts): one row at most,
 * replaced by the next episode.
 *
 * @property int $id
 * @property string $guid the RSS item's guid (or its enclosure URL when it has none)
 * @property int|null $episode_number
 * @property string $title
 * @property int $published_at unix time of the RSS pubDate
 * @property string $transcript
 * @property string|null $digest Haiku's digest for the TV voice, once written
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['guid', 'episode_number', 'title', 'podcast', 'published_at', 'transcript', 'digest'])]
class CasterPodcastTranscript extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['episode_number' => 'integer', 'published_at' => 'integer'];
    }
}
