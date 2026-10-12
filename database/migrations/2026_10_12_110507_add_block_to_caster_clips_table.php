<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE Radio (plan "TWENTY ONE Radio", P6): a programme block the
     * workstation rendered whole (several scripts, jingles, blips, one
     * loudness pass). Its metadata: the scripts it covers, the segment
     * boundaries where a chat reaction may cut in, and that it is dressed
     * already (the stream adds no stinger of its own). Null for a plain clip.
     */
    public function up(): void
    {
        Schema::table('caster_clips', function (Blueprint $table) {
            $table->json('block')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caster_clips', function (Blueprint $table) {
            $table->dropColumn('block');
        });
    }
};
