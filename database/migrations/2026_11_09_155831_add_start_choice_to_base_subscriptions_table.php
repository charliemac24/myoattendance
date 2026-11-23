<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A trial school's choice of when its first paid period starts: "after_trial" or "immediate".
     */
    public function up(): void
    {
        Schema::table('base_subscriptions', function (Blueprint $table): void {
            $table->string('start_choice', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('base_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('start_choice');
        });
    }
};
