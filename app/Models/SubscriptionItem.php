<?php

namespace App\Models;

class SubscriptionItem extends \Laravel\Cashier\SubscriptionItem
{
    protected $connection = 'mysql';
}
