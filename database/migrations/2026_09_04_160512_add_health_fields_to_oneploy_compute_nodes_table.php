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
        Schema::table('oneploy_compute_nodes', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('is_draining');
            $table->timestamp('last_probe_failed_at')->nullable()->after('last_seen_at');
            $table->text('last_probe_error')->nullable()->after('last_probe_failed_at');
            $table->unsignedSmallInteger('consecutive_probe_failures')->default(0)->after('last_probe_error');
            $table->index(
                ['is_draining', 'last_probe_failed_at', 'last_seen_at'],
                'oneploy_compute_nodes_scheduler_health_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oneploy_compute_nodes', function (Blueprint $table) {
            $table->dropIndex('oneploy_compute_nodes_scheduler_health_index');
            $table->dropColumn([
                'last_seen_at',
                'last_probe_failed_at',
                'last_probe_error',
                'consecutive_probe_failures',
            ]);
        });
    }
};
