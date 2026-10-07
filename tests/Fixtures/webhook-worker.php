<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'testing' || config('database.connections.shared_users.database') !== 'testing') {
    fwrite(STDERR, 'Worker refused non-testing configuration: '.json_encode([$app->environment(), config('database.connections.mysql.database'), config('database.connections.shared_users.database')])."\n");
    exit(2);
}
config()->set('cashier.webhook.secret', 'whsec_fixture_secret');
config()->set('services.stripe.demo_price_id', 'price_concurrent');
$body = stream_get_contents(STDIN);
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture_secret');
echo "READY\n";
flush();
$request = Request::create('/stripe/webhook', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
], $body);
$response = $kernel->handle($request);
echo $response->getStatusCode()."\n";
$kernel->terminate($request, $response);
