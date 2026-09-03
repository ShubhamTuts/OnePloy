<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oneploy_domains', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name']);
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('oneploy_domains', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['team_id', 'name']);
        });
    }
};
