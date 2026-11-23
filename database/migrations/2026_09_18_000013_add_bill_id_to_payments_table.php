<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete()->after('organization_id');
            $table->index(['bill_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['bill_id']);
            $table->dropIndex(['bill_id', 'status']);
            $table->dropColumn('bill_id');
        });
    }
};
