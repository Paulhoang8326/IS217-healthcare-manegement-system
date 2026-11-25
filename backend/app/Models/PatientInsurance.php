<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientInsurance extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'patient_insurance';

    protected $primaryKey = 'patient_insurance_id';

    public $timestamps = false;

    protected $fillable = [
        'patient_id',
        'insurance_num',
        'status',
        'expiry_date',
        'coverage_percent',
    ];

    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    protected function casts(): array
    {
        return [
            'patient_insurance_id' => 'integer',
            'patient_id' => 'integer',
            'expiry_date' => 'date',
            'coverage_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }
}
