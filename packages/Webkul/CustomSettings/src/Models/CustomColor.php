<?php

namespace Webkul\CustomSettings\Models;

use Illuminate\Database\Eloquent\Model;

class CustomColor extends Model
{
    protected $table = 'custom_colors';

    protected $fillable = ['key', 'value'];
}
