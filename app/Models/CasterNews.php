<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A headline for the TWENTY ONE TV news segment (App\Support\Caster\NewsFeeds,
 * App\Support\Caster\NewsScripts): read from a public feed, spoken at most
 * once, deleted after `tv.commentary.news.keep_hours`.
 *
 * @property int $id
 * @property string $hash sha256 of the normalised headline
 * @property string $section bitcoin or germany
 * @property string $feed the key of the feed in tv.commentary.news.feeds
 * @property string $source the outlet as spoken ("tagesschau")
 * @property string $language de or en
 * @property string $title
 * @property string|null $link
 * @property Carbon $published_at
 * @property Carbon|null $used_at spoken, or turned down (`skipped` says why)
 * @property string|null $skipped
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('caster_news')]
#[Fillable(['hash', 'section', 'feed', 'source', 'language', 'title', 'link', 'published_at', 'used_at', 'skipped'])]
class CasterNews extends Model
{
    public const BITCOIN = 'bitcoin';

    public const GERMANY = 'germany';

    public const SECTIONS = [self::BITCOIN, self::GERMANY];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
