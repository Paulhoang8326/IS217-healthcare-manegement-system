<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\ShiftType;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Room;
use App\Modules\Scheduling\Repositories\DoctorRepository;
use App\Modules\Scheduling\Repositories\DoctorScheduleRepository;
use App\Modules\Scheduling\Repositories\RoomRepository;
use App\Modules\Scheduling\Resources\DoctorScheduleImportResponse;
use App\Modules\Scheduling\Resources\DoctorScheduleResponse;
use App\Shared\Exceptions\BusinessException;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Exceptions\ResourceNotFoundException;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

class DoctorScheduleService
{
    public function __construct(
        private DoctorScheduleRepository $doctorScheduleRepository,
        private DoctorRepository $doctorRepository,
        private RoomRepository $roomRepository,
    ) {}

    public function create(array $request): array
    {
        return DB::transaction(function () use ($request) {
            $this->validateScheduleDate($request['scheduleDate']);
            $doctor = $this->findDoctorOrThrow($request['doctorId']);
            $room = $this->findRoomOrThrow($request['roomId']);
            $this->validateDuplicateDoctorShift($request['doctorId'], $request['scheduleDate'], $request['shift'], null);
            $this->validateDuplicateRoomShift($request['roomId'], $request['scheduleDate'], $request['shift'], null);
            $schedule = new DoctorSchedule;
            $this->mapRequest($schedule, $request, $doctor, $room);
            $schedule->current_booking_count = 0;
            $schedule->last_queue_number = 0;

            return DoctorScheduleResponse::toResponse($this->saveWithDuplicateHandling($schedule));
        });
    }

    public function getAll(?string $date, ?string $startDate, ?string $endDate, ?int $doctorId, ?int $roomId): array
    {
        return $this->doctorScheduleRepository->findAllByFilters($date, $startDate, $endDate, $doctorId, $roomId)
            ->sort(function (DoctorSchedule $a, DoctorSchedule $b) {
                return [$a->getAttribute('schedule_date')->format('Y-m-d'), array_search($a->getAttribute('shift'), ShiftType::cases(), true), $a->doctor->doctor_id]
                    <=> [$b->getAttribute('schedule_date')->format('Y-m-d'), array_search($b->getAttribute('shift'), ShiftType::cases(), true), $b->doctor->doctor_id];
            })->values()->map(fn ($schedule) => DoctorScheduleResponse::toResponse($schedule))->all();
    }

    public function getById(int $id): array
    {
        return DoctorScheduleResponse::toResponse($this->findScheduleOrThrow($id));
    }

    public function update(int $id, array $request): array
    {
        return DB::transaction(function () use ($id, $request) {
            $this->validateScheduleDate($request['scheduleDate']);
            $schedule = $this->findScheduleOrThrow($id);
            $doctor = $this->findDoctorOrThrow($request['doctorId']);
            $room = $this->findRoomOrThrow($request['roomId']);
            $this->validateDuplicateDoctorShift($request['doctorId'], $request['scheduleDate'], $request['shift'], $id);
            $this->validateDuplicateRoomShift($request['roomId'], $request['scheduleDate'], $request['shift'], $id);
            if ($request['maxCapacity'] < $schedule->current_booking_count) {
                throw new BusinessException('Sức chứa tối đa không được nhỏ hơn số lượng đã đặt hiện tại');
            }
            $this->mapRequest($schedule, $request, $doctor, $room);

            return DoctorScheduleResponse::toResponse($this->saveWithDuplicateHandling($schedule));
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $this->findScheduleOrThrow($id);
            // QUIRK-NEW: Java exposes DELETE but always rejects deleting an existing schedule.
            throw new BusinessException('Không hỗ trợ xóa lịch làm việc bác sĩ');
        });
    }

    public function importSchedules(?UploadedFile $file): array
    {
        return DB::transaction(function () use ($file) {
            if ($file === null || $file->getSize() === 0) {
                throw new BusinessException('File tải lên trống');
            }
            $saved = [];
            $workbook = null;
            try {
                $workbook = IOFactory::load($file->getPathname());
                $sheet = $workbook->getSheet(0);
                for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
                    $doctorName = $this->getCellValueAsString($sheet->getCell('A'.$row));
                    $roomCode = $this->getCellValueAsString($sheet->getCell('B'.$row));
                    if ($doctorName === '' && $roomCode === '') {
                        continue;
                    }
                    $date = $this->getCellValueAsDate($sheet->getCell('C'.$row));
                    $shiftText = $this->getCellValueAsString($sheet->getCell('D'.$row));
                    $doctor = $this->doctorRepository->findActiveByFullName($doctorName)->first();
                    if ($doctor === null) {
                        throw new BusinessException('Không tìm thấy bác sĩ đang hoạt động với tên: '.$doctorName.' ở dòng '.$row);
                    }
                    $room = $this->roomRepository->findByRoomCodeNormalized($roomCode);
                    if ($room === null) {
                        throw new BusinessException('Không tìm thấy phòng với mã: '.$roomCode.' ở dòng '.$row);
                    }
                    if ($date === null) {
                        throw new BusinessException('Ngày trực không hợp lệ ở dòng '.$row);
                    }
                    $this->validateScheduleDate($date);
                    $shift = ShiftType::tryFrom(trim(mb_strtoupper($shiftText), "\x00..\x20"));
                    if ($shift === null) {
                        throw new BusinessException('Ca trực không hợp lệ (MORNING, AFTERNOON, EVENING, NIGHT): '.$shiftText.' ở dòng '.$row);
                    }
                    $this->validateDuplicateDoctorShift($doctor->doctor_id, $date, $shift->value, null);
                    $this->validateDuplicateRoomShift($room->room_id, $date, $shift->value, null);
                    // QUIRK-NEW: Java imports maxCapacity=0 although the schema requires max_capacity>0.
                    $schedule = new DoctorSchedule([
                        'doctor_id' => $doctor->doctor_id, 'room_id' => $room->room_id, 'schedule_date' => $date,
                        'shift' => $shift, 'max_capacity' => 0, 'current_booking_count' => 0, 'last_queue_number' => 0, 'note' => null,
                    ]);
                    $saved[] = $this->saveWithDuplicateHandling($schedule);
                }
            } catch (BusinessException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new BusinessException('Lỗi khi đọc file Excel: '.$e->getMessage(), previous: $e);
            } finally {
                $workbook?->disconnectWorksheets();
            }

            return DoctorScheduleImportResponse::toResponse(array_map(fn ($schedule) => DoctorScheduleResponse::toResponse($schedule), $saved));
        });
    }

    private function getCellValueAsString(Cell $cell): string
    {
        return match ($cell->getDataType()) {
            DataType::TYPE_STRING, DataType::TYPE_INLINE => trim((string) $cell->getValue(), "\x00..\x20"),
            DataType::TYPE_NUMERIC => (string) (int) max(-2147483648, min(2147483647, (float) $cell->getValue())),
            default => '',
        };
    }

    private function getCellValueAsDate(Cell $cell): ?string
    {
        if ($cell->getDataType() === DataType::TYPE_NUMERIC && Date::isDateTime($cell)) {
            return Date::excelToDateTimeObject($cell->getValue())->format('Y-m-d');
        }
        if ($cell->getDataType() !== DataType::TYPE_STRING) {
            return null;
        }
        $text = trim((string) $cell->getValue(), "\x00..\x20");
        if (str_contains($text, '/')) {
            if (! preg_match('~^(\d{2})/(\d{2})/(\d{4})$~D', $text, $m)
                || (int) $m[1] < 1 || (int) $m[1] > 31 || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1) {
                return null;
            }
            // QUIRK-NEW: DateTimeFormatter dd/MM/yyyy uses SMART resolution, clamping days to month end.
            $month = new DateTimeImmutable(sprintf('%04d-%02d-01', $m[3], $m[2]));

            return $month->format('Y-m-').sprintf('%02d', min((int) $m[1], (int) $month->format('t')));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
    }

    private function findScheduleOrThrow(int $id): DoctorSchedule
    {
        return $this->doctorScheduleRepository->findById($id)
            ?? throw new ResourceNotFoundException('Không tìm thấy lịch làm việc bác sĩ với id: '.$id);
    }

    private function findDoctorOrThrow(int $id): Doctor
    {
        $doctor = $this->doctorRepository->findById($id) ?? $this->doctorRepository->findByAccount_AccountId($id)
            ?? throw new ResourceNotFoundException('[SCHED] Không tìm thấy bác sĩ với id: '.$id);
        // QUIRK-NEW: Scheduling accepts account ID fallback and forcibly activates doctor and account.
        if (! $doctor->isActive() || ($doctor->account !== null && $doctor->account->is_active !== 1)) {
            $doctor->is_active = 1;
            if ($doctor->account !== null) {
                $doctor->account->is_active = 1;
            }
            $this->doctorRepository->save($doctor);
        }

        return $doctor;
    }

    private function findRoomOrThrow(int $id): Room
    {
        return $this->roomRepository->findById($id) ?? throw new ResourceNotFoundException('Không tìm thấy phòng với id: '.$id);
    }

    private function validateScheduleDate(?string $date): void
    {
        if ($date === null) {
            throw new BusinessException('Ngày làm việc không được để trống');
        }
        if ($date < today()->toDateString()) {
            throw new BusinessException('Ngày làm việc không được ở trong quá khứ');
        }
    }

    private function validateDuplicateDoctorShift(int $id, string $date, string $shift, ?int $except): void
    {
        if ($this->doctorScheduleRepository->existsByDoctor_DoctorIdAndScheduleDateAndShiftAndDoctorScheduleIdNot($id, $date, $shift, $except)) {
            throw new DuplicateResourceException('Bác sĩ đã có lịch ở ca này trong ngày đã chọn');
        }
    }

    private function validateDuplicateRoomShift(int $id, string $date, string $shift, ?int $except): void
    {
        if ($this->doctorScheduleRepository->existsByRoom_RoomIdAndScheduleDateAndShiftAndDoctorScheduleIdNot($id, $date, $shift, $except)) {
            throw new DuplicateResourceException('Phòng đã được phân công cho bác sĩ khác ở ca này trong ngày đã chọn');
        }
    }

    private function mapRequest(DoctorSchedule $schedule, array $data, Doctor $doctor, Room $room): void
    {
        $schedule->fill(['doctor_id' => $doctor->doctor_id, 'room_id' => $room->room_id, 'schedule_date' => $data['scheduleDate'],
            'shift' => $data['shift'], 'max_capacity' => $data['maxCapacity'], 'note' => $data['note'] ?? null]);
        $schedule->setRelation('doctor', $doctor)->setRelation('room', $room);
    }

    private function saveWithDuplicateHandling(DoctorSchedule $schedule): DoctorSchedule
    {
        try {
            return $this->doctorScheduleRepository->saveAndFlush($schedule);
        } catch (QueryException $e) {
            $message = strtoupper($e->getMessage());
            if (str_contains($message, 'UQ_SCHEDULE_DOCTOR_SHIFT')) {
                throw new DuplicateResourceException('Bác sĩ đã có lịch ở ca này trong ngày đã chọn');
            }
            if (str_contains($message, 'UQ_SCHEDULE_ROOM_SHIFT')) {
                throw new DuplicateResourceException('Phòng đã được phân công cho bác sĩ khác ở ca này trong ngày đã chọn');
            }
            throw $e;
        }
    }
}
