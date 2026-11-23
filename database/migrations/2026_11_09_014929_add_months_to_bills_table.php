<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bills can cover several months, and there are only two plans: `base` and `package` (Base + SMS).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('bills', 'months')) {
            Schema::table('bills', function (Blueprint $table): void {
                $table->unsignedTinyInteger('months')->default(1)->after('bill_type');
            });
        }

        // SMS-only bills were never offered in production; retire any created during development.
        DB::table('bills')->where('bill_type', 'sms')->whereIn('status', ['unpaid', 'cancelled'])->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('months');
        });
    }
};
