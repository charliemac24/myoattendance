<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->boolean('enabled')->default(false);
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'feature']);
            $table->index(['feature', 'enabled', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_entitlements');
    }
};
