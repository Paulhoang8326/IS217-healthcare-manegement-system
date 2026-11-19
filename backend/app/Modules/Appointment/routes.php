<?php

use App\Modules\Appointment\Controllers\AppointmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('appointments')->controller(AppointmentController::class)->group(function (): void {
    Route::post('/', 'create');
    Route::post('/walk-in', 'createWalkInPaidAppointment')->middleware('role:RECEPTIONIST');
    Route::post('/sepay/webhook', 'handleSepayWebhook');
    Route::get('/', 'getAll');
    Route::get('/{id}', 'getById');
    Route::post('/{id}/check-in', 'checkIn');
    Route::post('/{id}/start', 'start');
    Route::post('/{id}/cancel', 'cancel');
    Route::post('/{id}/confirm-payment-manual', 'confirmManualPayment')->middleware('role:RECEPTIONIST');
});
