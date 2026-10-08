<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneTimePayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount' => 'integer'];
    }
}
