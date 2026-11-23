<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unpaid break (e.g. a 60-minute lunch) taken out of a worked shift when computing hours.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('periods');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('break_minutes');
        });
    }
};
