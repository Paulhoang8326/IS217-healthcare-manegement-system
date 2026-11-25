<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'appointment';

    protected $primaryKey = 'appointment_id';

    public $timestamps = false;

    protected $fillable = [
        'patient_id',
        'doctor_schedule_id',
        'fee_id',
        'queue_num',
        'appointment_code',
        'status',
        'initial_symptoms',
        'visit_reason',
        'fee_name_snapshot',
        'fee_price_snapshot',
        'created_at',
        'updated_at',
        'paid_at',
        'payment_expires_at',
        'sepay_transaction_id',
        'payment_reference_code',
        'payment_content',
        'checked_in_at',
        'cancelled_at',
        'version_number',
    ];

    protected $attributes = [
        'version_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'appointment_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_schedule_id' => 'integer',
            'fee_id' => 'integer',
            'queue_num' => 'integer',
            'status' => AppointmentStatus::class,
            'fee_price_snapshot' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_expires_at' => 'datetime',
            'sepay_transaction_id' => 'integer',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    /**
     * @return BelongsTo<DoctorSchedule, $this>
     */
    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_schedule_id', 'doctor_schedule_id');
    }

    /**
     * @return BelongsTo<ConsultationFee, $this>
     */
    public function consultationFee(): BelongsTo
    {
        return $this->belongsTo(ConsultationFee::class, 'fee_id', 'fee_id');
    }

    /**
     * @return HasOne<PaymentRecord, $this>
     */
    public function paymentRecord(): HasOne
    {
        return $this->hasOne(PaymentRecord::class, 'appointment_id', 'appointment_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->created_at ??= now();
            $appointment->status ??= AppointmentStatus::PENDING;
        });

        static::updating(function (Appointment $appointment) {
            $appointment->updated_at = now();
        });
    }
}
