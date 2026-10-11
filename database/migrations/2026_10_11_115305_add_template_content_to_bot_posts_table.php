<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The template wording of a note the league voice wrote instead (plan
     * "KI-Stimme der Liga", P2). PrideNotes compares the facts of its next
     * note with its last one by their template wording; a voiced note's own
     * text never matches a template, so the template is kept beside it.
     */
    public function up(): void
    {
        Schema::table('bot_posts', function (Blueprint $table) {
            $table->text('template_content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_posts', function (Blueprint $table) {
            $table->dropColumn('template_content');
        });
    }
};
