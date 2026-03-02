<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('custom_promotions', function (Blueprint $table) {
            $table->string('banner_path', 2048)->nullable()->after('is_active');
            $table->string('logo_path', 2048)->nullable()->after('banner_path');
        });
    }

    public function down(): void
    {
        Schema::table('custom_promotions', function (Blueprint $table) {
            $table->dropColumn(['banner_path', 'logo_path']);
        });
    }
};
