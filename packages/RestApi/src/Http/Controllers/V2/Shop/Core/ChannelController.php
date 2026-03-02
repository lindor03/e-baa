<?php

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Core;

use Webkul\Core\Repositories\ChannelRepository;
use Webkul\RestApi\Http\Resources\V2\Shop\Core\ChannelResource;

class ChannelController extends CoreController
{
    /**
     * Is resource authorized.
     */
    public function isAuthorized(): bool
    {
        return false;
    }

    /**
     * Repository class name.
     */
    public function repository(): string
    {
        return ChannelRepository::class;
    }

    /**
     * Resource class name.
     */
    public function resource(): string
    {
        return ChannelResource::class;
    }
}
