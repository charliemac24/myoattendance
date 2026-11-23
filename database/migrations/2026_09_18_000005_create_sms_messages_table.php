<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('attendance_event_id')->constrained('attendance_events')->cascadeOnDelete();
            $table->string('notification_type', 32);
            $table->string('recipient_phone', 32);
            $table->text('message');
            $table->string('status', 16)->default('pending');
            $table->string('provider', 32)->default('semaphore');
            $table->string('sender_name', 32)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->json('provider_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['attendance_event_id', 'notification_type', 'recipient_phone'], 'sms_event_type_recipient_unique');
            $table->index(['status', 'next_attempt_at', 'created_at'], 'sms_worker_claim_index');
            $table->index(['organization_id', 'created_at'], 'sms_org_created_index');
            $table->index(['person_id', 'created_at'], 'sms_person_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
