<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_type', 32)->default('sms_addon')->after('organization_id');
            $table->string('status', 32)->default('approved')->after('payment_type');
            $table->string('proof_path')->nullable()->after('reference_number');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete()->after('recorded_by');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('submitted_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->index(['status', 'payment_type']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['status', 'payment_type']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropColumn(['payment_type', 'status', 'proof_path', 'submitted_by', 'reviewed_by', 'reviewed_at', 'rejection_reason']);
        });
    }
};
