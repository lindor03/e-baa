<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Category\Models\Category;

class AutomotiveCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            $root = Category::findOrFail(1); // Bagisto default root

            $categories = require database_path('data/automotive_categories.php');

            foreach ($categories as $category) {
                $this->createCategory($category, $root->id);
            }

        });
    }

    private function createCategory(array $data, int $parentId, int $depth = 1): void
    {
        if ($depth > 4) {
            return;
        }

        $category = Category::create([
            'parent_id'    => $parentId,
            'position'     => 1,
            'status'       => 1,
            'display_mode' => 'products_and_description',
        ]);

        $category->translations()->create([
            'locale' => 'en',
            'name'   => $data['name'],
            'slug'   => Str::slug($data['name']),
        ]);

        if (!empty($data['children'])) {
            foreach ($data['children'] as $child) {
                $this->createCategory($child, $category->id, $depth + 1);
            }
        }
    }
}
