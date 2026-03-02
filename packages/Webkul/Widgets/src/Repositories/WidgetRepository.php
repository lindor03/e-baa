<?php

namespace Webkul\Widgets\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Widgets\Models\Widget;

class WidgetRepository extends Repository
{
    public function model()
    {
        // If you use contracts normally, adjust. For now, direct model.
        return Widget::class;
    }

    /**
     * Get all enabled widgets sorted for frontend.
     */
    public function getActiveWidgetsForHome()
    {
        return $this->model
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get();
    }
}
