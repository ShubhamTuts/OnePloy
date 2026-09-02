<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oneploy_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('family')->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('oneploy_products')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'slug']);
        });

        Schema::create('oneploy_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('oneploy_plans')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->json('features')->nullable();
            $table->json('entitlements');
            $table->json('included_usage')->nullable();
            $table->json('regions')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'version']);
        });

        Schema::create('oneploy_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('oneploy_plan_versions')->cascadeOnDelete();
            $table->char('currency', 3)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('interval');
            $table->string('status')->default('active');
            $table->string('provider')->nullable();
            $table->string('provider_price_id')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
            $table->index(['currency', 'interval', 'status']);
        });

        Schema::create('oneploy_checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->char('currency', 3);
            $table->string('locale')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('items');
            $table->json('attribution')->nullable();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('coupon_code')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('checkout_session_id')->nullable()->constrained('oneploy_checkout_sessions')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->json('lines');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('oneploy_orders')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->json('lines');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('oneploy_invoices')->nullOnDelete();
            $table->string('provider');
            $table->string('status')->default('pending');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('provider_reference')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_commerce_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('plan_version_id')->constrained('oneploy_plan_versions');
            $table->foreignId('price_id')->nullable()->constrained('oneploy_prices')->nullOnDelete();
            $table->string('status')->default('active');
            $table->string('legacy_stripe_subscription_id')->nullable()->index();
            $table->string('legacy_stripe_price_id')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->json('entitlement_snapshot');
            $table->timestamps();
        });

        Schema::create('oneploy_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamps();
        });

        Schema::create('oneploy_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('oneploy_wallets')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('reason');
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_usage_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('meter');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->string('period');
            $table->json('dimensions')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'meter', 'period']);
        });

        Schema::create('oneploy_quota_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('reserved');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'resource_type', 'status']);
        });

        Schema::create('oneploy_compute_pools', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('region')->nullable();
            $table->json('workload_classes')->nullable();
            $table->boolean('is_managed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('oneploy_compute_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compute_pool_id')->constrained('oneploy_compute_pools')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->json('labels')->nullable();
            $table->boolean('is_draining')->default(false);
            $table->timestamps();
            $table->unique(['compute_pool_id', 'server_id']);
        });

        Schema::create('oneploy_capacity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compute_node_id')->constrained('oneploy_compute_nodes')->cascadeOnDelete();
            $table->unsignedInteger('cpu_millis_available')->nullable();
            $table->unsignedInteger('memory_mb_available')->nullable();
            $table->unsignedInteger('disk_gb_available')->nullable();
            $table->unsignedInteger('gpu_available')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('oneploy_workload_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compute_node_id')->nullable()->constrained('oneploy_compute_nodes')->nullOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('workload_class');
            $table->string('status')->default('reserved');
            $table->string('idempotency_key')->unique();
            $table->json('requirements')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_placement_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workload_reservation_id')->nullable()->constrained('oneploy_workload_reservations')->nullOnDelete();
            $table->foreignId('compute_node_id')->nullable()->constrained('oneploy_compute_nodes')->nullOnDelete();
            $table->json('inputs');
            $table->json('scores')->nullable();
            $table->text('explanation')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name')->index();
            $table->string('status')->default('pending');
            $table->string('registrar')->nullable();
            $table->string('provider_reference')->nullable();
            $table->boolean('privacy')->default(false);
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->json('nameservers')->nullable();
            $table->json('contacts')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });

        Schema::create('oneploy_dns_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('oneploy_domains')->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->json('records')->nullable();
            $table->boolean('dnssec')->default(false);
            $table->timestamps();
        });

        Schema::create('oneploy_payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('provider_event_id')->index();
            $table->string('status')->default('received');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('oneploy_impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->text('reason');
            $table->boolean('restricted')->default(true);
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oneploy_marketplace_apps', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('certification')->default('community');
            $table->string('product_level')->default('template');
            $table->string('template_file')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oneploy_marketplace_apps');
        Schema::dropIfExists('oneploy_impersonation_sessions');
        Schema::dropIfExists('oneploy_payment_webhook_events');
        Schema::dropIfExists('oneploy_dns_zones');
        Schema::dropIfExists('oneploy_domains');
        Schema::dropIfExists('oneploy_placement_decisions');
        Schema::dropIfExists('oneploy_workload_reservations');
        Schema::dropIfExists('oneploy_capacity_snapshots');
        Schema::dropIfExists('oneploy_compute_nodes');
        Schema::dropIfExists('oneploy_compute_pools');
        Schema::dropIfExists('oneploy_quota_reservations');
        Schema::dropIfExists('oneploy_usage_ledgers');
        Schema::dropIfExists('oneploy_wallet_entries');
        Schema::dropIfExists('oneploy_wallets');
        Schema::dropIfExists('oneploy_commerce_subscriptions');
        Schema::dropIfExists('oneploy_payments');
        Schema::dropIfExists('oneploy_invoices');
        Schema::dropIfExists('oneploy_orders');
        Schema::dropIfExists('oneploy_checkout_sessions');
        Schema::dropIfExists('oneploy_prices');
        Schema::dropIfExists('oneploy_plan_versions');
        Schema::dropIfExists('oneploy_plans');
        Schema::dropIfExists('oneploy_products');
    }
};
