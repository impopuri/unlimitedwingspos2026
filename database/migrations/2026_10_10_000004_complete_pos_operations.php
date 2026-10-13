<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedInteger('cost_per_unit_cents')->default(0);
            $table->string('supplier')->nullable();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->index();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('discount_cents')->default(0);
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('reference')->nullable();
            $table->string('paid_with', 20)->default('cash');
            $table->boolean('voided')->default(false)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('product_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_per_product');
            $table->timestamps();
            $table->unique(['product_id', 'inventory_item_id']);
        });

        Schema::create('cash_drawer_sessions', function (Blueprint $table): void {
            $table->id();
            $table->date('business_date')->index();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('opening_cash_cents');
            $table->unsignedInteger('expected_closing_cash_cents')->nullable();
            $table->unsignedInteger('actual_counted_cash_cents')->nullable();
            $table->integer('variance_cents')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'business_date', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('payment_method', 20);
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('application_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('cash_drawer_sessions');
        Schema::dropIfExists('product_recipes');
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['reference', 'paid_with', 'voided']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('discount_cents');
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['active']);
            $table->dropColumn('active');
        });
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn(['cost_per_unit_cents', 'supplier']);
        });
    }
};
