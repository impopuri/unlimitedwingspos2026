<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_order_number', 20)->nullable()->unique();
        });

        DB::table('orders')->orderBy('id')->each(function (object $order): void {
            DB::table('orders')->where('id', $order->id)->update([
                'customer_order_number' => 'ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['customer_order_number']);
            $table->dropColumn('customer_order_number');
        });
    }
};
