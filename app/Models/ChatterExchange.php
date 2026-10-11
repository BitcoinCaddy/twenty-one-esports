<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Keywords of one spoken reaction to a chatter: the commentary's memory of
 * earlier exchanges, the last `exchanges_per_person` per pubkey.
 *
 * @property int $id
 * @property string $pubkey
 * @property string $keywords
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'keywords'])]
class ChatterExchange extends Model {}
