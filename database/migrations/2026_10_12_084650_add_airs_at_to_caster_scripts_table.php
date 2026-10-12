<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE Radio (plan "TWENTY ONE Radio", P1): a news bulletin is
     * written and voiced ahead but airs at its slot (:00, :30 Berlin time,
     * App\Support\Caster\Programme\BroadcastClock). Null for every other script.
     */
    public function up(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->timestamp('airs_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->dropColumn('airs_at');
        });
    }
};
