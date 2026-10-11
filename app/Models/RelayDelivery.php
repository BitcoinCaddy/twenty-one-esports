<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The answer of one relay to one published event: the `OK` flag and message
 * (NIP-01), or null plus an error when the relay never answered.
 *
 * @property int $id
 * @property int $nostr_event_id
 * @property string $relay
 * @property bool|null $accepted
 * @property string|null $message
 * @property Carbon $attempted_at
 * @property int $attempts how often it was sent to this relay (RelayRetries caps the retries)
 */
#[Fillable(['nostr_event_id', 'relay', 'accepted', 'message', 'attempted_at', 'attempts'])]
class RelayDelivery extends Model
{
    /** @var array<string, mixed> the column default, so a new row reads the same before and after saving */
    protected $attributes = ['attempts' => 1];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'attempted_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }
}
