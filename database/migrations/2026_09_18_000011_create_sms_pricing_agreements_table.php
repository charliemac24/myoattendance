<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_pricing_agreements')) {
            return;
        }

        Schema::create('sms_pricing_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sms_monthly_price_minor');
            $table->char('currency', 3)->default('PHP');
            $table->unsignedInteger('person_count_snapshot');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('agreed_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'effective_from'], 'sms_pricing_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_pricing_agreements');
    }
};
