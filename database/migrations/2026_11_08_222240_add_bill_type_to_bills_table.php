<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bills become typed so the base subscription and the SMS add-on can be billed
     * separately (`base`, `sms`) or together (`package`).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('bills', 'bill_type')) {
            Schema::table('bills', function (Blueprint $table): void {
                $table->string('bill_type', 16)->default('base')->after('organization_id');
            });
        }

        $packageBillIds = DB::table('bill_items')->where('type', 'sms_addon')->pluck('bill_id');
        if ($packageBillIds->isNotEmpty()) {
            DB::table('bills')->whereIn('id', $packageBillIds)->update(['bill_type' => 'package']);
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->unique(['organization_id', 'bill_type', 'billing_period_start', 'billing_period_end'], 'bills_type_period_unique');
        });
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->unique(['organization_id', 'billing_period_start', 'billing_period_end'], 'bills_period_unique');
        });
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_type_period_unique');
            $table->dropColumn('bill_type');
        });
    }
};
