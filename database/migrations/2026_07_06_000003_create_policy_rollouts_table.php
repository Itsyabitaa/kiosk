<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_rollouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('device_groups')->nullOnDelete();
            $table->unsignedTinyInteger('rollout_percentage')->default(100);
            $table->timestamp('scheduled_at')->nullable();
            // scheduled -> in_progress -> (paused) -> completed | rolled_back
            $table->string('status')->default('scheduled');
            $table->timestamps();

            $table->index(['org_id', 'status']);
        });

        Schema::table('policy_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('rollout_id')->nullable()->after('status');
            // The policy the device had before this rollout overwrote it (for rollback).
            $table->unsignedBigInteger('previous_policy_id')->nullable()->after('rollout_id');
        });
    }

    public function down(): void
    {
        Schema::table('policy_assignments', function (Blueprint $table) {
            $table->dropColumn(['rollout_id', 'previous_policy_id']);
        });

        Schema::dropIfExists('policy_rollouts');
    }
};
