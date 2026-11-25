<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalServiceResult extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'medical_service_result';

    protected $primaryKey = 'med_service_result_id';

    public $timestamps = false;

    protected $fillable = [
        'med_ser_req_id',
        'result_data',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'med_service_result_id' => 'integer',
            'med_ser_req_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MedicalServiceRequest, $this>
     */
    public function medicalServiceRequest(): BelongsTo
    {
        return $this->belongsTo(MedicalServiceRequest::class, 'med_ser_req_id', 'med_ser_req_id');
    }

    protected static function booted(): void
    {
        static::creating(function (MedicalServiceResult $result) {
            $result->created_at ??= now();
        });
    }
}
