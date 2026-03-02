<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_flat', function (Blueprint $table) {
            // keep types consistent with your table (product_id is unsigned int(10))
            $table->unsignedInteger('promotion_id')->nullable()->after('special_price_to');
            $table->index('promotion_id', 'product_flat_promotion_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_flat', function (Blueprint $table) {
            $table->dropIndex('product_flat_promotion_id_index');
            $table->dropColumn('promotion_id');
        });
    }
};
