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
        Schema::table('oneploy_capacity_snapshots', function (Blueprint $table) {
            $table->index(
                ['compute_node_id', 'captured_at'],
                'oneploy_capacity_snapshots_node_captured_index'
            );
        });

        Schema::table('oneploy_workload_reservations', function (Blueprint $table) {
            $table->unsignedInteger('cpu_millis')->default(0)->after('requirements');
            $table->unsignedInteger('memory_mb')->default(0)->after('cpu_millis');
            $table->unsignedInteger('disk_gb')->default(0)->after('memory_mb');
            $table->unsignedInteger('gpu')->default(0)->after('disk_gb');
            $table->string('workload_reference')->nullable()->after('gpu');
            $table->timestamp('consumed_at')->nullable()->after('expires_at');
            $table->timestamp('released_at')->nullable()->after('consumed_at');
            $table->dropUnique('oneploy_workload_reservations_idempotency_key_unique');
            $table->unique(
                ['team_id', 'idempotency_key'],
                'oneploy_workload_reservations_team_idempotency_unique'
            );
            $table->unique(
                ['team_id', 'workload_reference'],
                'oneploy_workload_reservations_team_workload_unique'
            );
            $table->index(
                ['compute_node_id', 'status', 'expires_at'],
                'oneploy_workload_reservations_active_capacity_index'
            );
        });

        Schema::table('oneploy_placement_decisions', function (Blueprint $table) {
            $table->unique(
                'workload_reservation_id',
                'oneploy_placement_decisions_reservation_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oneploy_placement_decisions', function (Blueprint $table) {
            $table->dropUnique('oneploy_placement_decisions_reservation_unique');
        });

        Schema::table('oneploy_workload_reservations', function (Blueprint $table) {
            $table->dropIndex('oneploy_workload_reservations_active_capacity_index');
            $table->dropUnique('oneploy_workload_reservations_team_workload_unique');
            $table->dropUnique('oneploy_workload_reservations_team_idempotency_unique');
            $table->unique('idempotency_key');
            $table->dropColumn([
                'cpu_millis',
                'memory_mb',
                'disk_gb',
                'gpu',
                'workload_reference',
                'consumed_at',
                'released_at',
            ]);
        });

        Schema::table('oneploy_capacity_snapshots', function (Blueprint $table) {
            $table->dropIndex('oneploy_capacity_snapshots_node_captured_index');
        });
    }
};
