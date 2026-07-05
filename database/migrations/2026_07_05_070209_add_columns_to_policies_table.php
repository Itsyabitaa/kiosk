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
        Schema::table('policies', function (Blueprint $table) {
            $table->string('name')->after('org_id');
            $table->unsignedBigInteger('group_id')->nullable()->after('name');
            $table->string('target')->nullable()->after('policy_type');
            $table->json('restrictions')->nullable()->after('target');
            $table->string('status')->default('draft')->after('version'); // draft, published

            $table->foreign('group_id')->references('id')->on('policies')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn(['name', 'group_id', 'target', 'restrictions', 'status']);
        });
    }
};
