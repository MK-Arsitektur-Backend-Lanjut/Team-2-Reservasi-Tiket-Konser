<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

class ReservationsAutoCancelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:auto-cancel';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto cancel expired reservations and mark payment as failed';

    /**
     * Execute the console command.
     */
    public function handle(PaymentService $paymentService): int
    {
        $total = $paymentService->autoCancelExpiredReservations();

        $this->info("Expired reservations processed: {$total}");

        return self::SUCCESS;
    }
}
