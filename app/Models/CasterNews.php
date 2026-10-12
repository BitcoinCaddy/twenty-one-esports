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
 * @property string|null $summary the feed item's teaser text (description), plain, at most `news.max_summary_chars`
 * @property string|null $link
 * @property Carbon $published_at
 * @property Carbon|null $used_at spoken, or turned down (`skipped` says why)
 * @property string|null $skipped
 * @property Carbon|null $bulletin_at read in a news bulletin (App\Support\Caster\Programme\NewsBulletin); never again in one
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('caster_news')]
#[Fillable(['hash', 'section', 'feed', 'source', 'language', 'title', 'summary', 'link', 'published_at', 'used_at', 'skipped', 'bulletin_at'])]
class CasterNews extends Model
{
    public const BITCOIN = 'bitcoin';

    public const GERMANY = 'germany';

    /** Foreign news (plan "TWENTY ONE Radio", P5): read in the bulletins only, not in the ten-minute news segments. */
    public const WORLD = 'world';

    public const SECTIONS = [self::BITCOIN, self::GERMANY, self::WORLD];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'used_at' => 'datetime',
            'bulletin_at' => 'datetime',
        ];
    }
}
