<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rolling chat memory of TWENTY ONE TV (App\Support\Caster\ChatMemory):
     * every trusted chat message the caster saw (sanitised, at most 200
     * characters) with the speakable name and, once spoken, the start of
     * Markus' reply. Kept `tv.commentary.chat.memory_hours` (caster:prune);
     * untrusted chatters are never stored.
     */
    public function up(): void
    {
        Schema::create('chatter_lines', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64);
            $table->string('name', 64)->nullable();
            $table->string('message', 210);
            $table->string('reply', 300)->nullable();
            $table->unsignedBigInteger('said_at');
            $table->timestamps();

            $table->index(['pubkey', 'said_at']);
            $table->index('said_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatter_lines');
    }
};
