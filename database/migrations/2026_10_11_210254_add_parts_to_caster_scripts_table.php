<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE TV commentary: a long script is queued in parts
     * (App\Support\Caster\ScriptQueue::enqueue(), live 2026-10-11: a wished
     * Bauernkrieg episode of 27 lines left 2.5 minutes of silence while the
     * workstation voiced it as one clip). The parts of one script share
     * `part_group`; `part_index` counts from 1. Both null for a script in one
     * piece.
     */
    public function up(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->string('part_group', 26)->nullable();
            $table->unsignedSmallInteger('part_index')->nullable();

            $table->index(['part_group', 'part_index']);
        });
    }

    public function down(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->dropIndex(['part_group', 'part_index']);
            $table->dropColumn(['part_group', 'part_index']);
        });
    }
};
