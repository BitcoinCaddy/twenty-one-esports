<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE Radio (plan "TWENTY ONE Radio", P2): when a headline was read
     * in a news bulletin (App\Support\Caster\Programme\NewsBulletin); a
     * headline goes into at most one bulletin.
     */
    public function up(): void
    {
        Schema::table('caster_news', function (Blueprint $table) {
            $table->timestamp('bulletin_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_news', function (Blueprint $table) {
            $table->dropColumn('bulletin_at');
        });
    }
};
