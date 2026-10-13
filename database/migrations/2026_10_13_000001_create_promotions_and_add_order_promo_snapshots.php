<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description');
            $table->unsignedSmallInteger('companions_required');
            $table->unsignedSmallInteger('free_customers_per_group');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_name')->nullable();
            $table->unsignedSmallInteger('promotion_companions_required')->nullable();
            $table->unsignedSmallInteger('promotion_free_customers')->default(0);
            $table->unsignedInteger('promotion_savings_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn([
                'promotion_name',
                'promotion_companions_required',
                'promotion_free_customers',
                'promotion_savings_cents',
            ]);
        });

        Schema::dropIfExists('promotions');
    }
};
