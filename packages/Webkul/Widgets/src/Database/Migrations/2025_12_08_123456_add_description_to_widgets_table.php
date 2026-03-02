<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDescriptionToWidgetsTable extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            // Add description column after title
            $table->string('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            // Drop the description column if rolling back
            $table->dropColumn('description');
        });
    }
}
