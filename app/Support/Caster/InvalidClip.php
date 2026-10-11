<?php

namespace App\Support\Caster;

use RuntimeException;

/** An upload that is not an Opus clip within the per-clip limits (ClipSpool::store()). */
final class InvalidClip extends RuntimeException {}
