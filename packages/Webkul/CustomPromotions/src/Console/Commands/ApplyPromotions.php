<?php

namespace Webkul\CustomPromotions\Console\Commands;

use Illuminate\Console\Command;
use Webkul\CustomPromotions\Jobs\ApplyPromotionsJob;

class ApplyPromotions extends Command
{
    protected $signature = 'custom-promotions:apply';
    protected $description = 'Apply active custom promotions to product_flat';

    public function handle(): int
    {
        dispatch_sync(new ApplyPromotionsJob());
        $this->info('Promotions applied.');
        return self::SUCCESS;
    }
}
