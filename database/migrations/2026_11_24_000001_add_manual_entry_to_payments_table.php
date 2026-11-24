<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments the super admin typed in with "Record payment" (cash, GCash or bank transfer the school never submitted).
     * Earlier cash records were all entered that way.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('manual_entry')->default(false)->after('payment_method');
        });
        DB::table('payments')->where('payment_method', 'cash')->update(['manual_entry' => true]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('manual_entry');
        });
    }
};
