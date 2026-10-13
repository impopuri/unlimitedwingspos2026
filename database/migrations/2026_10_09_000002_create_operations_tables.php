<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('void_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('category', 60);
            $table->string('description');
            $table->unsignedInteger('amount_cents');
            $table->timestamp('spent_at')->index();
            $table->timestamps();
        });

        Schema::create('cash_flow_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('amount_cents');
            $table->string('description');
            $table->string('reference')->nullable()->unique();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('kitchen_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->timestamps();
            $table->index(['kitchen_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_status_history');
        Schema::dropIfExists('cash_flow_entries');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('void_requests');
        Schema::dropIfExists('audit_logs');
    }
};
