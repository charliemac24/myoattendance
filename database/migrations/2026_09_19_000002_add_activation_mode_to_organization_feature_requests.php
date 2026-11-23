<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_feature_requests', 'activation_mode')) {
            Schema::table('organization_feature_requests', function (Blueprint $table): void {
                $table->string('activation_mode', 24)->default('after_trial')->after('quote_sent_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organization_feature_requests', 'activation_mode')) {
            Schema::table('organization_feature_requests', function (Blueprint $table): void {
                $table->dropColumn('activation_mode');
            });
        }
    }
};
