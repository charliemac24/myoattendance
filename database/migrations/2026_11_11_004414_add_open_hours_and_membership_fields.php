<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups can let people check in anytime ("open hours", e.g. a gym) instead of on a schedule, and a person can
     * have a membership end date after which scans are refused.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->string('attendance_mode', 16)->default('scheduled')->after('name');
            $table->boolean('scan_out')->default(false)->after('attendance_mode');
        });

        Schema::table('persons', function (Blueprint $table): void {
            $table->date('membership_valid_until')->nullable()->after('contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->dropColumn('membership_valid_until');
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn(['attendance_mode', 'scan_out']);
        });
    }
};
