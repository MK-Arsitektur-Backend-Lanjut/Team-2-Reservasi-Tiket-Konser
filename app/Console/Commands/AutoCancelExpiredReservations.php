<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class AutoCancelExpiredReservations extends Command
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
    protected $description = 'Expire semua reservasi yang hold-nya sudah habis dan kembalikan kursi ke available.';

    public function __construct(
        private readonly ReservationService $reservationService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('[' . now()->toDateTimeString() . '] Memproses reservasi expired...');

        $count = $this->reservationService->expireHolds();

        if ($count > 0) {
            $this->info("✓ {$count} reservasi telah di-expire dan kursinya dikembalikan ke available.");
        } else {
            $this->line('  Tidak ada reservasi yang perlu di-expire.');
        }

        return Command::SUCCESS;
    }
}
