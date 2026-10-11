<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a drawn deciding game of a two-leg pairing (Blockli) is settled
 * (user, 2026-10-11, DerCaddy's proposal in PR #5): `standing` = the side
 * ahead in the race standing of the final position wins, a level standing
 * is replayed; `replay` = replayed up to the league's limit, then the seed.
 *
 * Additive and null for every row: null reads as `replay`, so existing
 * tournaments keep the rule their players signed up under. New Blockli
 * tournaments are created with `standing` (the create page's default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('draw_decider', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('draw_decider');
        });
    }
};
