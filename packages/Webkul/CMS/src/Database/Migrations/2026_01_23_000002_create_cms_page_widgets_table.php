<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_page_widgets', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('cms_page_id');
            $table->unsignedBigInteger('widget_id');

            // Where this widget appears on the page
            $table->string('position')->default('content');
            // Order inside the position
            $table->unsignedInteger('sort_order')->default(0);

            // Allow per-page enable/disable
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['cms_page_id', 'widget_id', 'position'],
                'cms_page_widget_unique'
            );

            $table->foreign('cms_page_id')
                ->references('id')
                ->on('cms_pages')
                ->cascadeOnDelete();

            $table->foreign('widget_id')
                ->references('id')
                ->on('widgets')
                ->cascadeOnDelete();

            $table->index(['cms_page_id', 'position', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_page_widgets');
    }
};
