<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('org_id');
        });

        Schema::create('device_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('device_groups')->cascadeOnDelete();
            $table->timestamps();

            // A device can only belong to a given group once.
            $table->unique(['device_id', 'group_id']);
            $table->index('group_id');
        });

        // Tag-based grouping as a lightweight alternative/complement to explicit groups.
        Schema::create('device_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('tag');
            $table->timestamps();

            $table->unique(['device_id', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tags');
        Schema::dropIfExists('device_group_memberships');
        Schema::dropIfExists('device_groups');
    }
};
