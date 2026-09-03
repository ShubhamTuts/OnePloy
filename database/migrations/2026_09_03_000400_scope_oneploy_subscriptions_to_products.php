<?php

use App\Models\OneployCommerceSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oneploy_commerce_subscriptions', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('team_id')
                ->constrained('oneploy_products')
                ->restrictOnDelete();
        });

        OneployCommerceSubscription::query()
            ->with('planVersion.plan')
            ->eachById(function (OneployCommerceSubscription $subscription): void {
                $subscription->update([
                    'product_id' => $subscription->planVersion?->plan?->product_id,
                ]);
            });

        Schema::table('oneploy_commerce_subscriptions', function (Blueprint $table) {
            $table->unique(['team_id', 'product_id'], 'oneploy_subscriptions_team_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('oneploy_commerce_subscriptions', function (Blueprint $table) {
            $table->dropUnique('oneploy_subscriptions_team_product_unique');
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
