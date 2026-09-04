<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oneploy_ai_gateway_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('request_hash', 64);
            $table->string('provider');
            $table->string('model');
            $table->string('upstream_model');
            $table->char('billing_period', 7);
            $table->unsignedBigInteger('reserved_tokens');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('upstream_status')->nullable();
            $table->longText('response_payload')->nullable();
            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->string('error_code')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['team_id', 'idempotency_key_hash'],
                'oneploy_ai_req_team_idem_unique',
            );
            $table->index(
                ['team_id', 'created_at'],
                'oneploy_ai_req_team_created_index',
            );
            $table->index(
                ['team_id', 'billing_period', 'status'],
                'oneploy_ai_req_budget_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oneploy_ai_gateway_requests');
    }
};
