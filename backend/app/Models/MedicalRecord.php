<?php

namespace App\Models;

use App\Enums\MedicalRecordConclusionType;
use App\Enums\MedicalRecordStatus;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecord extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'medical_record';

    protected $primaryKey = 'med_record_id';

    public $timestamps = false;

    protected $fillable = [
        'appointment_id',
        'doctor_id',
        'patient_id',
        'initial_diagnosis',
        'clinical_conclusion',
        'conclusion_type',
        'clinical_notes',
        'treatment_plan',
        'status',
        'total_price',
        'created_at',
        'updated_at',
        'completed_at',
        'locked_at',
        'version_number',
    ];

    protected $attributes = [
        'version_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'med_record_id' => 'integer',
            'appointment_id' => 'integer',
            'doctor_id' => 'integer',
            'patient_id' => 'integer',
            'conclusion_type' => MedicalRecordConclusionType::class,
            'status' => MedicalRecordStatus::class,
            'total_price' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'completed_at' => 'datetime',
            'locked_at' => 'datetime',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id', 'appointment_id');
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'doctor_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    protected static function booted(): void
    {
        static::creating(function (MedicalRecord $record) {
            $record->created_at ??= now();
            $record->status ??= MedicalRecordStatus::DRAFT;
            $record->total_price ??= '0.00';
        });

        static::updating(function (MedicalRecord $record) {
            $record->updated_at = now();
        });
    }
}
