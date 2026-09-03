<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oneploy_domains', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->foreignId('checkout_session_id')
                ->nullable()
                ->after('team_id')
                ->constrained('oneploy_checkout_sessions')
                ->nullOnDelete();
            $table->char('currency', 3)->nullable()->after('provider_reference');
            $table->unsignedBigInteger('amount_minor')->nullable()->after('currency');
            $table->unsignedSmallInteger('years')->default(1)->after('amount_minor');
            $table->unsignedSmallInteger('provisioning_attempts')->default(0)->after('years');
            $table->text('last_error')->nullable()->after('provisioning_attempts');
            $table->timestamp('provisioned_at')->nullable()->after('last_error');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('oneploy_domains', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('checkout_session_id');
            $table->dropUnique(['uuid']);
            $table->dropColumn([
                'uuid',
                'currency',
                'amount_minor',
                'years',
                'provisioning_attempts',
                'last_error',
                'provisioned_at',
            ]);
        });
    }
};
