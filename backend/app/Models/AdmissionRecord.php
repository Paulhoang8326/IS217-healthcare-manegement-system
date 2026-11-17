<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionRecord extends Model
{
    protected $table = 'admission_record';

    protected $primaryKey = 'admission_record_id';

    public $timestamps = false;

    protected $fillable = [
        'admission_id',
        'blood_pressure',
        'heart_rate',
        'temperature',
        'record_date',
    ];

    protected function casts(): array
    {
        return [
            'admission_record_id' => 'integer',
            'admission_id' => 'integer',
            'heart_rate' => 'integer',
            'temperature' => 'float',
            'record_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdmissionRequest, $this>
     */
    public function admissionRequest(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequest::class, 'admission_id', 'admission_id');
    }

    protected static function booted(): void
    {
        static::creating(function (AdmissionRecord $record) {
            $record->record_date ??= now();
        });
    }
}
