<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('email', 'username');
            $table->dropColumn('email_verified_at');
            $table->boolean('active')->default(true)->after('role')->index();
        });

        foreach ([
            'admin@etivacwings.local' => 'admin',
            'cashier@etivacwings.local' => 'cashier',
            'kitchen@etivacwings.local' => 'kitchen',
        ] as $oldUsername => $username) {
            DB::table('users')->where('username', $oldUsername)->update(['username' => $username]);
        }

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['active']);
            $table->dropColumn('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->renameColumn('username', 'email');
        });
    }
};
