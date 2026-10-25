<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the last failed attempt saw (user 2026-10-25: "invoice_mismatch" alone said nothing): the LNURL answer,
     * the callback, the decoded invoice, the failed checks or the wallet's error, shown on the admin payouts page.
     */
    public function up(): void
    {
        foreach (['tournament_payouts', 'season_payouts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('debug')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['tournament_payouts', 'season_payouts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('debug');
            });
        }
    }
};
