<?php

use App\Modules\Scheduling\Controllers\DoctorScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('doctor-schedules')->controller(DoctorScheduleController::class)->group(function (): void {
    Route::post('/', 'create')->middleware('role:ADMIN,RECEPTIONIST');
    Route::post('/import', 'importSchedules')->middleware('role:ADMIN,RECEPTIONIST');
    Route::get('/', 'getAll')->middleware('role:ADMIN,RECEPTIONIST,DOCTOR,PATIENT');
    Route::get('/{id}', 'getById')->middleware('role:ADMIN,RECEPTIONIST,DOCTOR,PATIENT');
    Route::put('/{id}', 'update')->middleware('role:ADMIN,RECEPTIONIST');
    Route::delete('/{id}', 'delete')->middleware('role:ADMIN,RECEPTIONIST');
});
