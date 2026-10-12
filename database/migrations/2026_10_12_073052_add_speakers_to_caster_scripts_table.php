<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE TV commentary duets (App\Support\Caster\DuetDirector, user
     * 2026-10-12: Sirius as side commentator next to Markus): the speaker of
     * every line of a script, aligned with its sentences ("markus" or
     * "sirius"). Null for a script Markus speaks alone.
     */
    public function up(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->json('speakers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->dropColumn('speakers');
        });
    }
};
