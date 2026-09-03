<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oneploy_checkout_sessions', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('status')->index();
            $table->string('provider_reference')->nullable()->after('provider')->index();
            $table->text('approval_url')->nullable()->after('provider_reference');
            $table->text('failure_reason')->nullable()->after('approval_url');
            $table->json('provider_payload')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('oneploy_checkout_sessions', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropIndex(['provider_reference']);
            $table->dropColumn([
                'provider',
                'provider_reference',
                'approval_url',
                'failure_reason',
                'provider_payload',
            ]);
        });
    }
};
