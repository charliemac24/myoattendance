<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('PHP');
            $table->string('payment_method', 32)->default('gcash');
            $table->string('reference_number', 128)->nullable();
            $table->timestamp('paid_at');
            $table->date('coverage_start')->nullable();
            $table->date('coverage_end')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'paid_at']);
            $table->index(['payment_method', 'paid_at']);
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
