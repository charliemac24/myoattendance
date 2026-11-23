<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->time('payment_time')->nullable()->after('paid_at');
            $table->string('proof_request_reason', 1000)->nullable()->after('rejection_reason');
            $table->foreignId('proof_requested_by')->nullable()->after('proof_request_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('proof_requested_at')->nullable()->after('proof_requested_by');
            $table->boolean('proof_unavailable')->default(false)->after('proof_requested_at');
            $table->index(['status', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['proof_requested_by']);
            $table->dropIndex(['status', 'bill_id']);
            $table->dropColumn(['payment_time', 'proof_request_reason', 'proof_requested_by', 'proof_requested_at', 'proof_unavailable']);
        });
    }
};
