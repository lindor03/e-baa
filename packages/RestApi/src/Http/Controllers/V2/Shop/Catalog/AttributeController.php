<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\CatalogController;

class AttributeController extends CatalogController
{
    public function isAuthorized(): bool
    {
        return false;
    }

    public function repository(): string
    {
        return \Webkul\Attribute\Repositories\AttributeRepository::class;
    }

    public function resource(): string
    {
        return \Webkul\RestApi\Http\Resources\V2\Shop\Catalog\AttributeResource::class;
    }

    /**
     * Ultra-fast attribute listing for the shop API.
     */
    public function allResources(Request $request)
    {
        $locale  = app()->getLocale();
        $perPage = (int) $request->get('per_page', 10);
        $page    = max((int) $request->get('page', 1), 1);
        $offset  = ($page - 1) * $perPage;

        // Filter if light mode
        $filters = $request->boolean('light')
            ? "WHERE a.is_user_defined = 1 AND a.is_visible_on_front = 1"
            : "";

        // Main attributes
        $attributes = DB::select("
            SELECT
                a.id,
                COALESCE(at.name, a.admin_name) AS name,
                a.admin_name,
                a.code,
                a.type,
                a.swatch_type,
                a.is_user_defined,
                a.is_visible_on_front,
                a.is_filterable,
                a.position,
                a.created_at,
                a.updated_at
            FROM attributes a
            LEFT JOIN attribute_translations at
                ON at.attribute_id = a.id AND at.locale = ?
            $filters
            ORDER BY a.position ASC
            LIMIT ? OFFSET ?
        ", [$locale, $perPage, $offset]);

        // Get attribute IDs
        $ids = array_column($attributes, 'id');

        $options = [];
        if (!empty($ids)) {
            $rawOptions = DB::select("
                SELECT
                    o.id,
                    o.admin_name,
                    o.attribute_id,
                    o.swatch_value,
                    COALESCE(ot.label, o.admin_name) AS label,
                    COALESCE(ot.description, '') AS description
                FROM attribute_options o
                LEFT JOIN attribute_option_translations ot
                    ON ot.attribute_option_id = o.id AND ot.locale = ?
                WHERE o.attribute_id IN (".implode(',', $ids).")
                ORDER BY o.sort_order ASC
            ", [$locale]);

            foreach ($rawOptions as $opt) {

                // Only return options ending with "*"
                if (!str_ends_with($opt->admin_name, '*')) {
                    continue;
                }

                // Trim the trailing "*"
                $cleanName  = rtrim($opt->admin_name, '*');
                $cleanLabel = rtrim($opt->label, '*');

                $originalName  = $opt->admin_name;   // keep original
                $originalLabel = $opt->label;        // keep original

                $options[$opt->attribute_id][] = [
                    'id'           => (int) $opt->id,
                    'admin_name'   => $originalName,
                    'label'        => $originalLabel,
                    'swatch_value' => $opt->swatch_value,
                    'description'  => $opt->description,
                ];
            }
        }

        // Build API payload
        $data = array_map(function ($attr) use ($options) {
            return [
                'id'                  => (int) $attr->id,
                'name'                => $attr->name,
                'admin_name'          => $attr->admin_name,
                'code'                => $attr->code,
                'type'                => $attr->type,
                'swatch_type'         => $attr->swatch_type,
                'options'             => $options[$attr->id] ?? [],
                'is_user_defined'     => (int) $attr->is_user_defined,
                'is_visible_on_front' => (int) $attr->is_visible_on_front,
                'is_filterable'       => (int) $attr->is_filterable,
                'position'            => (int) $attr->position,
                'created_at'          => $attr->created_at,
                'updated_at'          => $attr->updated_at,
            ];
        }, $attributes);

        // Count for pagination
        $total = DB::table('attributes')
            ->when(
                $request->boolean('light'),
                fn($q) => $q->where('is_user_defined', 1)->where('is_visible_on_front', 1)
            )
            ->count();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ]);
    }
}
