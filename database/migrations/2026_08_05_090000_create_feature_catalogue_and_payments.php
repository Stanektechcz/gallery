<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the fixed plan/module pair into a three-layer model:
 *
 *   features            — the catalogue of what the system can do
 *   billing_plan_feature— which features a plan grants (operator-editable)
 *   space_features      — which of the granted features the customer wants on
 *
 * A preference can only ever hide something the customer is entitled to; it can never
 * unlock anything. Payments arrive alongside so a plan change can be tied to a real
 * Comgate transaction instead of an administrator toggling it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('code', 60)->unique();
                $table->string('name', 120);
                $table->string('tagline', 190)->nullable();
                $table->text('description')->nullable();
                $table->string('category', 40)->default('general');
                $table->string('icon', 16)->default('✨');
                $table->string('route', 120)->nullable();       // where the feature lives in the UI
                // Core features cannot be locked or switched off — the product makes no sense without them.
                $table->boolean('is_core')->default(false);
                // Optional features may be hidden by the customer even when granted.
                $table->boolean('is_optional')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // Which features each plan grants. Editable by the operator, so the offering can
        // change without a deployment.
        if (! Schema::hasTable('billing_plan_feature')) {
            Schema::create('billing_plan_feature', function (Blueprint $table) {
                $table->id();
                $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['billing_plan_id', 'feature_id'], 'plan_feature_unique');
            });
        }

        // A paid add-on grants one or more features on top of the plan.
        if (! Schema::hasTable('billing_module_feature')) {
            Schema::create('billing_module_feature', function (Blueprint $table) {
                $table->id();
                $table->foreignId('billing_module_id')->constrained()->cascadeOnDelete();
                $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['billing_module_id', 'feature_id'], 'module_feature_unique');
            });
        }

        // The customer's own choice within what they are entitled to.
        if (! Schema::hasTable('space_features')) {
            Schema::create('space_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->unique(['gallery_space_id', 'feature_id'], 'space_feature_unique');
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                // What is being bought.
                $table->string('purchase_type', 20);              // plan | module
                $table->foreignId('billing_plan_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('billing_module_id')->nullable()->constrained()->nullOnDelete();
                $table->string('billing_period', 12)->default('monthly');  // monthly | yearly
                // Money, in minor units so nothing rounds badly.
                $table->unsignedInteger('amount')->default(0);
                $table->string('currency', 3)->default('CZK');
                // Gateway bookkeeping.
                $table->string('gateway', 20)->default('comgate');
                $table->string('reference', 40)->unique();        // our refId sent to the gateway
                $table->string('transaction_id', 64)->nullable()->index();  // Comgate transId
                $table->string('status', 20)->default('pending');  // pending | paid | cancelled | failed | refunded
                $table->string('method', 40)->nullable();
                $table->string('payer_email', 190)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('gateway_payload')->nullable();       // last raw response/callback, for support
                $table->timestamps();
                $table->index(['gallery_space_id', 'status'], 'payments_space_status_idx');
            });
        }

        // Plans gain a group type and a yearly price; subscriptions gain a real period.
        Schema::table('billing_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_plans', 'group_type')) {
                // couple is the primary product; family and group are the wider offering.
                $table->string('group_type', 16)->default('couple')->after('code');
            }
            if (! Schema::hasColumn('billing_plans', 'price_yearly')) {
                $table->unsignedInteger('price_yearly')->default(0)->after('price_monthly');
            }
            if (! Schema::hasColumn('billing_plans', 'highlight')) {
                $table->boolean('highlight')->default(false)->after('is_default');
            }
        });

        Schema::table('space_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('space_subscriptions', 'billing_period')) {
                $table->string('billing_period', 12)->default('monthly')->after('status');
            }
            if (! Schema::hasColumn('space_subscriptions', 'current_period_ends_at')) {
                $table->timestamp('current_period_ends_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('space_subscriptions', 'last_payment_id')) {
                $table->unsignedBigInteger('last_payment_id')->nullable()->after('granted_by');
            }
        });

        Schema::table('space_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('space_modules', 'billing_period')) {
                $table->string('billing_period', 12)->default('monthly')->after('status');
            }
            if (! Schema::hasColumn('space_modules', 'current_period_ends_at')) {
                $table->timestamp('current_period_ends_at')->nullable()->after('activated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('space_features');
        Schema::dropIfExists('billing_module_feature');
        Schema::dropIfExists('billing_plan_feature');
        Schema::dropIfExists('features');

        Schema::table('billing_plans', function (Blueprint $table) {
            foreach (['group_type', 'price_yearly', 'highlight'] as $column) {
                if (Schema::hasColumn('billing_plans', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('space_subscriptions', function (Blueprint $table) {
            foreach (['billing_period', 'current_period_ends_at', 'last_payment_id'] as $column) {
                if (Schema::hasColumn('space_subscriptions', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('space_modules', function (Blueprint $table) {
            foreach (['billing_period', 'current_period_ends_at'] as $column) {
                if (Schema::hasColumn('space_modules', $column)) $table->dropColumn($column);
            }
        });
    }
};
