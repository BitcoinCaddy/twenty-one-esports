<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock topics like "stock:inflationsbeichte" overran the 16 characters (prod 2026-10-13: every claim answered 500).
     */
    public function up(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->string('topic', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('caster_scripts', function (Blueprint $table) {
            $table->string('topic', 16)->nullable()->change();
        });
    }
};
