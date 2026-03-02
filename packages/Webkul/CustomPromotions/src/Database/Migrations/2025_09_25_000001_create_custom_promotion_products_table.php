<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('custom_promotion_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedInteger('product_id'); // bagisto products.id
            $table->decimal('special_price', 12, 4);
            $table->timestamps();

            $table->unique(['promotion_id','product_id'], 'cpp_unique');

            $table->foreign('promotion_id')
                  ->references('id')->on('custom_promotions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void {
        Schema::dropIfExists('custom_promotion_products');
    }
};
