<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oneploy_checkout_sessions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->unique(['team_id', 'idempotency_key'], 'oneploy_checkout_team_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('oneploy_checkout_sessions', function (Blueprint $table) {
            $table->dropUnique('oneploy_checkout_team_idempotency_unique');
            $table->unique('idempotency_key');
        });
    }
};
