<?php

namespace App\Support\Caster;

use RuntimeException;

/** The spool is at its byte or seconds cap (ClipSpool::store()); the fetcher backs off. */
final class SpoolFull extends RuntimeException {}
