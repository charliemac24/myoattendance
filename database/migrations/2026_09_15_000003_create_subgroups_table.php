<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subgroups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id');
            $table->string('name');
            $table->timestamps();
            $table->unique(['id', 'organization_id', 'group_id']);
            $table->unique(['organization_id', 'group_id', 'name']);
            $table->foreign(['group_id', 'organization_id'])->references(['id', 'organization_id'])->on('groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subgroups');
    }
};
