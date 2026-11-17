<?php

namespace App\Models;

use App\Enums\AdmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionRequest extends Model
{
    protected $table = 'admission_request';

    protected $primaryKey = 'admission_id';

    public $timestamps = false;

    protected $fillable = [
        'patient_id',
        'med_record_id',
        'bed_id',
        'admission_date',
        'discharge_date',
        'status',
        'total_price',
    ];

    protected $attributes = [
        'status' => 'PENDING',
    ];

    protected function casts(): array
    {
        return [
            'admission_id' => 'integer',
            'patient_id' => 'integer',
            'med_record_id' => 'integer',
            'bed_id' => 'integer',
            'admission_date' => 'date',
            'discharge_date' => 'date',
            'status' => AdmissionStatus::class,
            'total_price' => 'decimal:2',
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
     * @return BelongsTo<MedicalRecord, $this>
     */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'med_record_id', 'med_record_id');
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'bed_id', 'bed_id');
    }
}
