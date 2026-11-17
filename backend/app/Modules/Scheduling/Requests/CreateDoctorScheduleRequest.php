<?php

namespace App\Modules\Scheduling\Requests;

use App\Shared\Http\Requests\BaseRequest;

class CreateDoctorScheduleRequest extends BaseRequest
{
    protected function validatesBody(): bool
    {
        return true;
    }

    protected function bodyFields(): array
    {
        return [
            'doctorId' => ['type' => 'Long', 'constraints' => [['name' => 'NotNull', 'message' => 'Bác sĩ không được để trống']]],
            'roomId' => ['type' => 'Long', 'constraints' => [['name' => 'NotNull', 'message' => 'Phòng không được để trống']]],
            'scheduleDate' => ['type' => 'LocalDate', 'constraints' => [
                ['name' => 'NotNull', 'message' => 'Ngày làm việc không được để trống'],
                ['name' => 'FutureOrPresent', 'message' => 'Ngày làm việc không được ở trong quá khứ'],
            ]],
            'shift' => ['type' => 'Enum', 'values' => ['MORNING', 'AFTERNOON'], 'constraints' => [['name' => 'NotNull', 'message' => 'Ca làm việc không được để trống']]],
            'maxCapacity' => ['type' => 'Integer', 'constraints' => [
                ['name' => 'NotNull', 'message' => 'Sức chứa tối đa không được để trống'],
                ['name' => 'Positive', 'message' => 'Sức chứa tối đa phải lớn hơn 0'],
                ['name' => 'Max', 'value' => 99999, 'message' => 'Sức chứa tối đa không được vượt quá 99999'],
            ]],
            'note' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 500, 'message' => 'Ghi chú không được vượt quá 500 ký tự']]],
        ];
    }
}
