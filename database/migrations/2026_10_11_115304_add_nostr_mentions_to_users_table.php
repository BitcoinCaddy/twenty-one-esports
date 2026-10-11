<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the league's bot may mention the player on Nostr (`nostr:npub1…`
     * and a `p` tag, plan "KI-Stimme der Liga", P2). On by default, as every
     * bot post did so far; switched off, posts name the player in plain text.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('nostr_mentions')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nostr_mentions');
        });
    }
};
