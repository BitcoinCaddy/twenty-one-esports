<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The claim heartbeat (App\Http\Controllers\CasterFetchController::extend()): while the workstation voices a
     * claimed script it renews the claim, so a long block never lapses mid-voicing, while a dead fetcher's claim still
     * frees after `slot_claim_seconds`. `claimed_at` stays the moment of the claim (VoiceBudget measures voicing from
     * it); null until the first renewal.
     */
    public function up(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->timestamp('renewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->dropColumn('renewed_at');
        });
    }
};
