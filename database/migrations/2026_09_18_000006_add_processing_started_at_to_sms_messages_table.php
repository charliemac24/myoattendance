<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->timestamp('processing_started_at')->nullable()->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropColumn('processing_started_at');
        });
    }
};
