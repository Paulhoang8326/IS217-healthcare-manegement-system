<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabTestResult extends Model
{
    protected $table = 'lab_test_result';

    protected $primaryKey = 'lab_test_result_id';

    public $timestamps = false;

    protected $fillable = [
        'lab_test_request_id',
        'result_data',
        'result_date',
    ];

    protected function casts(): array
    {
        return [
            'lab_test_result_id' => 'integer',
            'lab_test_request_id' => 'integer',
            'result_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LabTestRequest, $this>
     */
    public function labTestRequest(): BelongsTo
    {
        return $this->belongsTo(LabTestRequest::class, 'lab_test_request_id', 'lab_test_request_id');
    }

    protected static function booted(): void
    {
        static::creating(function (LabTestResult $result) {
            $result->result_date ??= now();
        });
    }
}
