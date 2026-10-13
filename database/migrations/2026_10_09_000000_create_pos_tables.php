<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('wing_count')->nullable();
            $table->unsignedSmallInteger('flavor_limit')->default(0);
            $table->json('inclusions')->nullable();
            $table->boolean('available')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('flavors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('unit', 16)->default('pcs');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('minimum_quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('paid');
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->string('package_snapshot')->nullable();
            $table->unsignedSmallInteger('paid_sets')->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('total_cents');
            $table->string('payment_method', 20);
            $table->unsignedInteger('amount_paid_cents');
            $table->unsignedInteger('change_cents')->default(0);
            $table->timestamps();
            $table->index(['created_at', 'type']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('line_total_cents');
            $table->timestamps();
        });

        Schema::create('order_item_flavors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flavor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('flavor_name');
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
        });

        Schema::create('unli_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_table_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('package_snapshot');
            $table->unsignedInteger('package_price_cents');
            $table->json('inclusions');
            $table->unsignedSmallInteger('paid_sets');
            $table->string('status', 20)->default('ongoing')->index();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['dining_table_id', 'status']);
        });

        Schema::create('unli_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unli_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->string('idempotency_key')->unique();
            $table->unsignedSmallInteger('total_wings');
            $table->text('instructions')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['unli_session_id', 'round_number']);
        });

        Schema::create('unli_round_flavors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unli_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flavor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('flavor_name');
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('movement_key')->unique();
            $table->integer('quantity_change');
            $table->string('reason');
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('unli_round_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('preparing')->index();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->unique('order_id');
            $table->unique('unli_round_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('unli_round_flavors');
        Schema::dropIfExists('unli_rounds');
        Schema::dropIfExists('unli_sessions');
        Schema::dropIfExists('order_item_flavors');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('flavors');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
