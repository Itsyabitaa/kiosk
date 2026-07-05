<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_telemetry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->string('connectivity_type')->nullable();
            $table->integer('signal_strength')->nullable();
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            // Supports "latest snapshot per device" + time-range history queries.
            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_telemetry');
    }
};
