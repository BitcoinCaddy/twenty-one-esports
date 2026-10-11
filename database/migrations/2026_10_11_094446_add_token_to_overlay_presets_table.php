<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The overlay URL's token, encrypted with APP_KEY (OverlayPreset casts it `encrypted`), so the admin page can show
     * every preset's URL for copying again (user, 2026-10-11: "die URLs sind nicht so geheim, dass man sie nur einmal
     * kopieren darf"). `token_hash` stays the lookup. Presets made before carry null until their next rotation.
     */
    public function up(): void
    {
        Schema::table('overlay_presets', function (Blueprint $table) {
            $table->text('token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('overlay_presets', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
