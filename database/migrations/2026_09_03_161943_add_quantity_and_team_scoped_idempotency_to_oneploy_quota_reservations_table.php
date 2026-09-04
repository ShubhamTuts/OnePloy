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
        Schema::table('oneploy_quota_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('quantity')->default(1)->after('resource_type');
            $table->dropUnique('oneploy_quota_reservations_idempotency_key_unique');
            $table->unique(
                ['team_id', 'idempotency_key'],
                'oneploy_quota_reservations_team_idempotency_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oneploy_quota_reservations', function (Blueprint $table) {
            $table->dropUnique('oneploy_quota_reservations_team_idempotency_unique');
            $table->dropColumn('quantity');
            $table->unique('idempotency_key');
        });
    }
};
