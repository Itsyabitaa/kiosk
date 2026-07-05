<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plan_tier')->default('free');
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role')->default('admin');
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('device_uid')->unique();
            $table->string('hardware_fingerprint')->nullable();
            $table->string('platform');
            $table->enum('enrollment_status', ['pending', 'enrolled', 'locked', 'unenrolled', 'error'])->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('policy_type');
            $table->json('config_json')->nullable();
            $table->integer('version')->default(1);
            $table->timestamps();
        });

        Schema::create('policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->string('status')->default('pending');
        });

        Schema::create('enrollment_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_tokens');
        Schema::dropIfExists('policy_assignments');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('organizations');
    }
};
