<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabTestRequestItem extends Model
{
    use HasCompositePrimaryKey;
    use StoresEmptyStringAsNull;

    protected $table = 'lab_test_request_item';

    protected $primaryKey = 'lab_test_request_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'lab_test_request_id',
        'lab_test_id',
        'snapshot_price',
    ];

    protected function casts(): array
    {
        return [
            'lab_test_request_id' => 'integer',
            'lab_test_id' => 'integer',
            'snapshot_price' => 'decimal:2',
        ];
    }

    public function getCompositeKeyNames(): array
    {
        return ['lab_test_request_id', 'lab_test_id'];
    }

    /**
     * @return BelongsTo<LabTestRequest, $this>
     */
    public function labTestRequest(): BelongsTo
    {
        return $this->belongsTo(LabTestRequest::class, 'lab_test_request_id', 'lab_test_request_id');
    }

    /**
     * @return BelongsTo<LabTest, $this>
     */
    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id', 'lab_test_id');
    }
}
