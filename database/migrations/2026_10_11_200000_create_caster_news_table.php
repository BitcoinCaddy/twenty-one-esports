<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE TV news segment: the headlines `caster:news` reads from
     * public RSS and Atom feeds (App\Support\Caster\NewsFeeds), one row per
     * headline, pruned by age and capped in rows. `hash` is the normalised
     * headline, so the same story from two feeds is stored once; `used_at`
     * marks a headline that was spoken or turned down, so it never comes back.
     */
    public function up(): void
    {
        Schema::create('caster_news', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 64)->unique();
            $table->string('section', 16);
            $table->string('feed', 32);
            $table->string('source', 64);
            $table->string('language', 2);
            $table->string('title', 300);
            $table->string('link', 500)->nullable();
            $table->timestamp('published_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->string('skipped', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caster_news');
    }
};
