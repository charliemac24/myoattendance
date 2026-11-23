<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bills') && Schema::hasTable('bill_items')) {
            return;
        }

        if (! Schema::hasTable('bills')) {
            Schema::create('bills', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->date('billing_period_start');
                $table->date('billing_period_end');
                $table->char('currency', 3)->default('PHP');
                $table->unsignedBigInteger('subtotal_minor')->default(0);
                $table->unsignedBigInteger('total_minor')->default(0);
                $table->string('status', 24)->default('unpaid');
                $table->date('due_at')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'billing_period_start', 'billing_period_end'], 'bills_period_unique');
                $table->index(['organization_id', 'status']);
            });
        }

        if (! Schema::hasTable('bill_items')) {
            Schema::create('bill_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
                $table->string('type', 32);
                $table->string('description', 255);
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedBigInteger('unit_amount_minor');
                $table->unsignedBigInteger('amount_minor');
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['bill_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('bills');
    }
};
