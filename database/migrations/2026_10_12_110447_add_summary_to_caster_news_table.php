<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE Radio (plan "TWENTY ONE Radio", P5): the feed item's teaser
     * text (RSS description, Atom summary), so a bulletin item and the news
     * talk can say why a story matters, not only its headline.
     */
    public function up(): void
    {
        Schema::table('caster_news', function (Blueprint $table) {
            $table->text('summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_news', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
