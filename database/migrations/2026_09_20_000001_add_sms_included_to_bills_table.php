<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bills', 'sms_included')) {
            Schema::table('bills', function (Blueprint $table): void {
                $table->boolean('sms_included')->default(false)->after('total_minor');
            });
        }

        $billIds = DB::table('bill_items')->where('type', 'sms_addon')->pluck('bill_id');
        if ($billIds->isNotEmpty()) {
            DB::table('bills')->whereIn('id', $billIds)->update(['sms_included' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bills', 'sms_included')) {
            Schema::table('bills', function (Blueprint $table): void {
                $table->dropColumn('sms_included');
            });
        }
    }
};
