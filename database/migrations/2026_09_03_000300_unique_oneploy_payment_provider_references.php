<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oneploy_payments', function (Blueprint $table) {
            $table->unique(['provider', 'provider_reference'], 'oneploy_payments_provider_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('oneploy_payments', function (Blueprint $table) {
            $table->dropUnique('oneploy_payments_provider_reference_unique');
        });
    }
};
