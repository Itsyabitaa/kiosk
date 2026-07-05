<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_commands', function (Blueprint $table) {
            // Delivery lifecycle timestamps for per-device delivery confirmation.
            $table->timestamp('delivered_at')->nullable()->after('status');
            $table->timestamp('acked_at')->nullable()->after('delivered_at');
            // Optional link back to a staged rollout wave that issued the command.
            $table->unsignedBigInteger('rollout_id')->nullable()->after('acked_at');

            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('mdm_commands', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'status']);
            $table->dropColumn(['delivered_at', 'acked_at', 'rollout_id']);
        });
    }
};
