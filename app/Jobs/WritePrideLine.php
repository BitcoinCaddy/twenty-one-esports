<?php

namespace App\Jobs;

use App\Support\Cards\PrideVoice;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes the model's line of one pride picture (PrideVoice::write()) in the
 * background, so no page or card waits for the model. Unique per line for
 * an hour: a card shown a hundred times asks once. One try: a refusal keeps
 * the template, and the next view after the lock asks again.
 */
class WritePrideLine implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(public string $function, public string $key, public array $facts) {}

    public function uniqueId(): string
    {
        return PrideVoice::cacheKey($this->function, $this->key, $this->facts);
    }

    public function handle(PrideVoice $voice): void
    {
        $voice->write($this->function, $this->key, $this->facts);
    }
}
