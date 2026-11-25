<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalServiceRequestItem extends Model
{
    use HasCompositePrimaryKey;
    use StoresEmptyStringAsNull;

    protected $table = 'medical_service_request_item';

    protected $primaryKey = 'med_ser_req_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'med_ser_req_id',
        'med_service_id',
        'snapshot_price',
    ];

    protected function casts(): array
    {
        return [
            'med_ser_req_id' => 'integer',
            'med_service_id' => 'integer',
            'snapshot_price' => 'decimal:2',
        ];
    }

    public function getCompositeKeyNames(): array
    {
        return ['med_ser_req_id', 'med_service_id'];
    }

    /**
     * @return BelongsTo<MedicalServiceRequest, $this>
     */
    public function medicalServiceRequest(): BelongsTo
    {
        return $this->belongsTo(MedicalServiceRequest::class, 'med_ser_req_id', 'med_ser_req_id');
    }

    /**
     * @return BelongsTo<MedicalService, $this>
     */
    public function medicalService(): BelongsTo
    {
        return $this->belongsTo(MedicalService::class, 'med_service_id', 'med_service_id');
    }
}
