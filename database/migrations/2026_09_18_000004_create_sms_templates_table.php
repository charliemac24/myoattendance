<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 32);
            $table->string('name', 80);
            $table->string('body', 1000);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'template_key']);
            $table->index(['organization_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
