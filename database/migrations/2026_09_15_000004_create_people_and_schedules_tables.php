<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('person_number', 64);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->foreignId('group_id')->nullable();
            $table->foreignId('subgroup_id')->nullable();
            $table->string('contact_number', 32)->nullable();
            $table->string('qr_token', 128)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'organization_id', 'group_id']);
            $table->unique(['organization_id', 'person_number']);
            $table->foreign(['group_id', 'organization_id'])->references(['id', 'organization_id'])->on('groups');
            $table->foreign(['subgroup_id', 'organization_id', 'group_id'])->references(['id', 'organization_id', 'group_id'])->on('subgroups');
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id');
            $table->foreignId('subgroup_id')->nullable();
            $table->string('name');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('late_after');
            $table->time('end_time')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'group_id', 'subgroup_id', 'day_of_week'], 'schedule_scope_day_unique');
            $table->foreign(['group_id', 'organization_id'])->references(['id', 'organization_id'])->on('groups')->cascadeOnDelete();
            $table->foreign(['subgroup_id', 'organization_id', 'group_id'])->references(['id', 'organization_id', 'group_id'])->on('subgroups');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('persons');
    }
};
