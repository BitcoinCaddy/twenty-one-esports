<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE TV followed-profile segment: the kind 1 notes `caster:followed`
     * reads from the profiles in `tv.commentary.followed_profiles`
     * (App\Support\Caster\FollowedNotes), one row per note id. `reacted_at`
     * marks a note that got its one reaction or was turned down (`skipped`
     * says why), so no note is ever spoken twice, across restarts too; the
     * newest `note_created_at` of a pubkey is its read cursor. Pruned by age
     * and capped in rows.
     */
    public function up(): void
    {
        Schema::create('caster_followed_notes', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('pubkey', 64);
            $table->timestamp('note_created_at')->index();
            $table->string('content', 1000);
            $table->timestamp('reacted_at')->nullable();
            $table->string('skipped', 32)->nullable();
            $table->timestamps();

            $table->index(['pubkey', 'note_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caster_followed_notes');
    }
};
