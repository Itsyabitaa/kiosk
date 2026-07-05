<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            // tamper | offline | telemetry_stopped
            $table->string('type');
            $table->string('severity')->default('warning');
            $table->string('message');
            $table->json('details')->nullable();
            // open | acknowledged | resolved
            $table->string('status')->default('open');
            $table->timestamps();

            $table->index(['org_id', 'status']);
            $table->index(['device_id', 'type']);
        });

        Schema::table('policy_assignments', function (Blueprint $table) {
            // Version the device reports as actually applied; compared against the assigned
            // policy version for compliance (mismatch => sync failure).
            $table->integer('applied_version')->nullable()->after('previous_policy_id');
        });
    }

    public function down(): void
    {
        Schema::table('policy_assignments', function (Blueprint $table) {
            $table->dropColumn('applied_version');
        });

        Schema::dropIfExists('alerts');
    }
};
