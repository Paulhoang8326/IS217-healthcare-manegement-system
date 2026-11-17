<?php

namespace App\Modules\Appointment\Console;

use App\Modules\Appointment\Services\AppointmentService;
use Illuminate\Console\Command;

class AppointmentPaymentReservationScheduler extends Command
{
    protected $signature = 'appointments:expire-payment-reservations';

    protected $description = 'Expire pending appointment payment reservations';

    public function handle(AppointmentService $service): int
    {
        $service->expirePendingPaymentReservations();

        return self::SUCCESS;
    }
}
