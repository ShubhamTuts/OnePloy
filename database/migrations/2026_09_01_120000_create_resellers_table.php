<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();

            // Aggregate limits across every tenant owned by this reseller. null = unlimited.
            $table->unsignedInteger('max_tenants')->nullable();
            $table->unsignedInteger('max_applications')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedInteger('max_services')->nullable();
            $table->unsignedInteger('max_containers')->nullable();
            $table->decimal('max_cpus', 8, 2)->nullable();
            $table->unsignedInteger('max_memory_mb')->nullable();
            $table->unsignedInteger('max_disk_gb')->nullable();

            // Pricing applied on top of platform prices when the reseller bills its tenants.
            $table->decimal('markup_percent', 6, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('default_plan')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};
