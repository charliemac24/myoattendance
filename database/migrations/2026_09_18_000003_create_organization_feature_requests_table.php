<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_feature_requests')) {
            $hasOrganizationIndex = collect(Schema::getIndexes('organization_feature_requests'))
                ->contains('name', 'org_feature_request_status_idx');

            if (! $hasOrganizationIndex) {
                Schema::table('organization_feature_requests', function (Blueprint $table): void {
                    $table->index(['organization_id', 'feature', 'status'], 'org_feature_request_status_idx');
                });
            }

            return;
        }

        Schema::create('organization_feature_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->string('status', 32);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['feature', 'status', 'requested_at'], 'feature_request_status_idx');
            $table->index(['organization_id', 'feature', 'status'], 'org_feature_request_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_feature_requests');
    }
};
