<?php

namespace App\Modules\Scheduling\Repositories;

use App\Models\Room;

class RoomRepository
{
    public function findById(int $id): ?Room
    {
        return Room::find($id);
    }

    public function findByRoomCodeNormalized(string $code): ?Room
    {
        return Room::whereRaw('UPPER(TRIM(room_code)) = UPPER(TRIM(?))', [$code])->first();
    }
}
