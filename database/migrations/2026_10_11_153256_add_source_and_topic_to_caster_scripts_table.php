<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE TV commentary (P6 yield round): who wrote a script (`ai`,
     * the league voice, or `pattern`, the deterministic fallback
     * App\Support\Caster\PatternScripts) and the slide topic it talks about
     * (App\Support\Caster\SceneTopic), so the stream can prefer the clip of
     * the slide on air.
     */
    public function up(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->string('source', 8)->default('ai');
            $table->string('topic', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->dropColumn(['source', 'topic']);
        });
    }
};
