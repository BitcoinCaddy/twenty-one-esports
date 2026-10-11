<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The night pause per tournament (user, 2026-10-10;
 * App\Support\Tournaments\NightPause): `night_pause` on or off, and its
 * `night_pause_until` / `night_pause_resume` times ("HH:MM", league zone).
 *
 * Additive and null for every row: null is the league default
 * (`esports.tournaments.night_pause`, on, 00:00 to 18:00), so every existing
 * tournament gets the pause without a data step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('night_pause')->nullable();
            $table->string('night_pause_until', 5)->nullable();
            $table->string('night_pause_resume', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['night_pause', 'night_pause_until', 'night_pause_resume']);
        });
    }
};
