<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaidPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime'];
    }
}
