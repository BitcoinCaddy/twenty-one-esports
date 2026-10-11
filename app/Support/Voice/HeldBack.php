<?php

namespace App\Support\Voice;

use RuntimeException;

/**
 * A profile note the spam gate turned down as a repetition (BotVoice
 * `hold_template`): not signed, not sent. Its producer leaves the claim to
 * expire and offers it again on a later run; a note whose moment passed
 * meanwhile falls out for good.
 */
final class HeldBack extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('held back by the spam gate as a repetition');
    }
}
