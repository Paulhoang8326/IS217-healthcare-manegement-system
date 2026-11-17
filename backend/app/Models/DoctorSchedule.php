<?php

namespace App\Models;

use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorSchedule extends Model
{
    protected $table = 'doctor_schedule';

    protected $primaryKey = 'doctor_schedule_id';

    public $timestamps = false;

    protected $fillable = [
        'doctor_id',
        'room_id',
        'schedule_date',
        'shift',
        'max_capacity',
        'current_booking_count',
        'last_queue_number',
        'note',
        'created_at',
        'updated_at',
        'version_number',
    ];

    protected $attributes = [
        'version_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'doctor_schedule_id' => 'integer',
            'doctor_id' => 'integer',
            'room_id' => 'integer',
            'schedule_date' => 'date',
            'shift' => ShiftType::class,
            'max_capacity' => 'integer',
            'current_booking_count' => 'integer',
            'last_queue_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'doctor_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    protected static function booted(): void
    {
        static::creating(function (DoctorSchedule $schedule) {
            $schedule->current_booking_count ??= 0;
            $schedule->last_queue_number ??= 0;
            $schedule->created_at ??= now();
        });

        static::updating(function (DoctorSchedule $schedule) {
            $schedule->updated_at = now();
        });
    }
}
