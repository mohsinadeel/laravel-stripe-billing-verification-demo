<?php

namespace App\Models;

class Subscription extends \Laravel\Cashier\Subscription
{
    protected $connection = 'mysql';
}
