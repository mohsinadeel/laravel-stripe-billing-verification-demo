<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoSetting extends Model
{
    protected $fillable = ['user_id', 'stripe_price_id'];
}
