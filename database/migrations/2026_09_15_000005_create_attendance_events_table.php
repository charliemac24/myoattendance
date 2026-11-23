<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id');
            $table->string('event_type', 32);
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['person_id', 'occurred_at']);
            $table->foreign(['person_id', 'organization_id'])->references(['id', 'organization_id'])->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
