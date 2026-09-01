<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('personal_team')->constrained('resellers')->nullOnDelete();
            $table->string('plan')->nullable()->after('reseller_id');
            $table->string('tenant_status')->default('active')->after('plan');
            $table->timestamp('suspended_at')->nullable()->after('tenant_status');

            // Per-tenant quota overrides. null = fall back to the plan value.
            $table->unsignedInteger('max_applications')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedInteger('max_services')->nullable();
            $table->unsignedInteger('max_containers')->nullable();
            $table->decimal('max_cpus', 8, 2)->nullable();
            $table->unsignedInteger('max_memory_mb')->nullable();
            $table->unsignedInteger('max_disk_gb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropColumn([
                'reseller_id',
                'plan',
                'tenant_status',
                'suspended_at',
                'max_applications',
                'max_databases',
                'max_services',
                'max_containers',
                'max_cpus',
                'max_memory_mb',
                'max_disk_gb',
            ]);
        });
    }
};
