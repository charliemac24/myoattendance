<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an SMS price came from: "admin" (set by Super Admin) or "estimate" (from the school's answers).
     */
    public function up(): void
    {
        Schema::table('sms_pricing_agreements', function (Blueprint $table): void {
            $table->string('source', 16)->default('admin')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sms_pricing_agreements', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
