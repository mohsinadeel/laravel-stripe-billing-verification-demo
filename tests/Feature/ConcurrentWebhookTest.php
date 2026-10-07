<?php

namespace Tests\Feature;

use App\Models\PaidPeriod;
use App\Models\StripeEventReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentWebhookTest extends TestCase
{
    protected bool $useTransactions = false;

    public function test_two_processes_deliver_one_signed_invoice_effect_and_one_receipt(): void
    {
        $reference = bin2hex(random_bytes(8));
        $user = User::factory()->create(['stripe_id' => 'cus_concurrent_'.$reference]);
        $eventId = 'evt_concurrent_'.$reference;
        $invoiceId = 'in_concurrent_'.$reference;
        $payload = ['id' => $eventId, 'type' => 'invoice.payment_succeeded', 'livemode' => false, 'data' => ['object' => [
            'id' => $invoiceId, 'customer' => $user->stripe_id, 'livemode' => false, 'status' => 'paid', 'amount_paid' => 1000,
            'parent' => ['subscription_details' => ['subscription' => 'sub_concurrent']],
            'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_concurrent']], 'period' => ['start' => 1791450000, 'end' => 1791453600]]]],
        ]]];
        $lock = substr('stripe-demo:'.hash('sha256', (string) $user->id), 0, 64);
        $workers = [];
        DB::select('SELECT GET_LOCK(?, 5)', [$lock]);
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/webhook-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_TABLE_PREFIX' => 'stripe_',
                ], json_encode($payload, JSON_THROW_ON_ERROR), 20);
                $process->start();
                $workers[] = $process;
            }
            foreach ($workers as $process) {
                $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')), $process->getErrorOutput().$process->getOutput());
            }
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
            foreach ($workers as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame("READY\n200\n", $process->getOutput());
            }
            $this->assertSame(1, PaidPeriod::where('stripe_invoice_id', $invoiceId)->count());
            $this->assertSame(1, StripeEventReceipt::where('stripe_event_id', $eventId)->count());
            $this->assertDatabaseHas('stripe_event_receipts', ['stripe_event_id' => $eventId, 'status' => 'completed', 'attempts' => 1]);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            PaidPeriod::where('stripe_invoice_id', $invoiceId)->delete();
            StripeEventReceipt::where('stripe_event_id', $eventId)->delete();
            $user->delete();
        }
    }
}
