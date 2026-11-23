<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A school's own answers for its SMS price estimate (all optional).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('sms_estimate_people')->nullable()->after('sms_sender_name');
            $table->json('sms_estimate_texts')->nullable()->after('sms_estimate_people');
            $table->string('sms_estimate_days', 16)->nullable()->after('sms_estimate_texts');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['sms_estimate_people', 'sms_estimate_texts', 'sms_estimate_days']);
        });
    }
};
