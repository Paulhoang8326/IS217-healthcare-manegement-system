<?php

namespace App\Modules\Appointment\Resources;

use App\Models\Appointment;
use App\Shared\Support\JavaDate;

final class AppointmentResponse
{
    public static function toResponse(?Appointment $appointment): ?array
    {
        if ($appointment === null) {
            return null;
        }
        $schedule = $appointment->doctorSchedule;
        $payment = $appointment->paymentRecord;
        $shift = $schedule?->getAttribute('shift');
        $number = static fn ($value) => $value === null ? null : (float) $value;

        return [
            'appointmentId' => $appointment->appointment_id,
            'patientId' => $appointment->patient?->patient_id,
            'patientName' => $appointment->patient?->full_name,
            'doctorScheduleId' => $schedule?->doctor_schedule_id,
            'doctorId' => $schedule?->doctor?->doctor_id,
            'doctorName' => $schedule?->doctor?->full_name,
            'feeId' => $appointment->consultationFee?->fee_id,
            'feeName' => $appointment->fee_name_snapshot,
            'feePrice' => $number($appointment->fee_price_snapshot),
            'scheduleDate' => JavaDate::localDate($schedule?->getAttribute('schedule_date')),
            'shift' => $shift?->value,
            'appointmentCode' => $appointment->appointment_code,
            'queueNum' => $appointment->queue_num,
            'status' => $appointment->getAttribute('status')?->value,
            'initialSymptoms' => $appointment->initial_symptoms,
            'visitReason' => $appointment->visit_reason,
            'createdAt' => JavaDate::localDateTime($appointment->getAttribute('created_at')),
            'updatedAt' => JavaDate::localDateTime($appointment->getAttribute('updated_at')),
            'paidAt' => JavaDate::localDateTime($appointment->getAttribute('paid_at')),
            'paymentExpiresAt' => JavaDate::localDateTime($appointment->getAttribute('payment_expires_at')),
            'expectedPaymentAmount' => $number($payment?->total_price),
            'receivedPaymentAmount' => $number($payment?->received_amount),
            'paymentStatus' => $payment?->getAttribute('payment_status')?->value,
            'sepayTransactionId' => $appointment->sepay_transaction_id,
            'paymentReferenceCode' => $appointment->payment_reference_code,
            'paymentContent' => $appointment->payment_content,
            'checkedInAt' => JavaDate::localDateTime($appointment->getAttribute('checked_in_at')),
            'cancelledAt' => JavaDate::localDateTime($appointment->getAttribute('cancelled_at')),
            'roomCode' => $schedule?->room?->room_code,
            'appointmentTime' => $shift === null ? null : $shift->getStartTime().' - '.$shift->getEndTime(),
        ];
    }
}
