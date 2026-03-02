<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('custom_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('erp_code');
            $table->dateTime('from')->nullable();
            $table->dateTime('to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'from', 'to'], 'custom_promotions_active_idx');
        });
    }

    public function down(): void {
        Schema::dropIfExists('custom_promotions');
    }
};
