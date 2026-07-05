<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Backs the fleet device-list query: org scoping + status filter + offline/recency
            // ordering, so it stays under budget with 1,000+ devices.
            $table->index(['org_id', 'enrollment_status', 'last_seen_at'], 'devices_org_status_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('devices_org_status_seen_idx');
        });
    }
};
