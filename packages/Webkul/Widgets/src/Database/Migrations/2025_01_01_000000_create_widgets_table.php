<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();

            // e.g. featured_products, products_by_attribute, products_by_category, carousel, video, html
            $table->string('type', 50);

            $table->string('title')->nullable();

            // Generic JSON config so we can support any widget type.
            $table->json('config')->nullable();

            // For home page ordering.
            $table->integer('sort_order')->default(0);

            // 1 = active, 0 = inactive
            $table->boolean('status')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
