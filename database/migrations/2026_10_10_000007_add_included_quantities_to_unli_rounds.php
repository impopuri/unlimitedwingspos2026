<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unli_rounds', function (Blueprint $table): void {
            $table->json('included_quantities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unli_rounds', function (Blueprint $table): void {
            $table->dropColumn('included_quantities');
        });
    }
};
