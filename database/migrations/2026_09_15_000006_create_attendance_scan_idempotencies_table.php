<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_scan_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('scan_request_id');
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->json('response');
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['organization_id', 'scan_request_id'], 'attendance_scan_idem_org_req_unique');
            $table->index(['organization_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_scan_idempotencies');
    }
};
