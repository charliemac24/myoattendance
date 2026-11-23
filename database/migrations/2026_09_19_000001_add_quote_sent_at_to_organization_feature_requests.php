<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organization_feature_requests', 'quote_sent_at')) {
            Schema::table('organization_feature_requests', function (Blueprint $table): void {
                $table->timestamp('quote_sent_at')->nullable()->after('requested_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organization_feature_requests', 'quote_sent_at')) {
            Schema::table('organization_feature_requests', function (Blueprint $table): void {
                $table->dropColumn('quote_sent_at');
            });
        }
    }
};
