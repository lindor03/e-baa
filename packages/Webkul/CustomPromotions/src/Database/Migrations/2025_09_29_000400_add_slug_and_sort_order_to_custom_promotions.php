<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('custom_promotions', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->after('name');
            $table->integer('sort_order')->default(0)->after('slug');

            $table->unique('slug', 'custom_promotions_slug_unique');
            $table->index('sort_order', 'custom_promotions_sort_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('custom_promotions', function (Blueprint $table) {
            $table->dropIndex('custom_promotions_sort_order_index');
            $table->dropUnique('custom_promotions_slug_unique');
            $table->dropColumn(['slug', 'sort_order']);
        });
    }
};
