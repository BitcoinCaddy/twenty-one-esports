<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often an event was sent to a relay (App\Support\Nostr\RelayRetries):
 * `nostr:republish` backs off between tries and stops after
 * RelayRetries::MAX_ATTEMPTS, instead of sending to a relay that refuses
 * (damus 403) every five minutes for good.
 *
 * Additive; an existing row counts as one attempt, so a relay that has
 * been failing for days gets the remaining tries with backoff, then stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relay_deliveries', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('relay_deliveries', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
