<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->string('type')->default('page')->after('layout');
            $table->unsignedInteger('position')->default(0)->after('type');
            $table->boolean('is_active')->default(true)->after('position');

            $table->index(['type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->dropIndex(['type', 'position']);
            $table->dropColumn(['type', 'position', 'is_active']);
        });
    }
};
