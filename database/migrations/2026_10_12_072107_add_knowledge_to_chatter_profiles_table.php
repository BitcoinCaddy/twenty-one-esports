<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the commentary knows about a trusted chatter (App\Support\Caster\ChatterCache):
     * the domain of their NIP-05 and of their website (never the address or the
     * path, never the lightning address), and room for the richer persona card
     * (`tv.commentary.chat.persona_chars`, 600).
     */
    public function up(): void
    {
        Schema::table('chatter_profiles', function (Blueprint $table) {
            $table->string('persona', 600)->nullable()->change();
            $table->string('nip05_domain', 100)->nullable()->after('about');
            $table->string('website_domain', 100)->nullable()->after('nip05_domain');
        });
    }

    public function down(): void
    {
        Schema::table('chatter_profiles', function (Blueprint $table) {
            $table->dropColumn(['nip05_domain', 'website_domain']);
            $table->string('persona', 400)->nullable()->change();
        });
    }
};
