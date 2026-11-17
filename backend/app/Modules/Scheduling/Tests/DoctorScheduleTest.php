<?php

namespace App\Modules\Scheduling\Tests;

use App\Models\Account;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Modules\Scheduling\Repositories\DoctorScheduleRepository;
use App\Shared\Exceptions\OptimisticLockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Shared\PersonOneModuleTestCase;

class DoctorScheduleTest extends PersonOneModuleTestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + ['doctorId' => $this->doctor->doctor_id, 'roomId' => $this->room->room_id,
            'scheduleDate' => '2026-11-18', 'shift' => 'MORNING', 'maxCapacity' => 5];
    }

    public function test_create_maps_complete_java_dto_and_keeps_null_fields(): void
    {
        $response = $this->postJson('/api/doctor-schedules', $this->payload(['unknown' => 'ignored']), $this->auth());
        $response->assertCreated()->assertJsonPath('doctorId', $this->doctor->doctor_id)
            ->assertJsonPath('doctorName', 'Doctor One')->assertJsonPath('roomCode', 'TEST-ROOM')
            ->assertJsonPath('shiftStartTime', '07:00')->assertJsonPath('shiftEndTime', '11:00')
            ->assertJsonPath('scheduleDate', '2026-11-18')->assertJsonPath('currentBookingCount', 0)
            ->assertJsonPath('lastQueueNumber', 0)->assertJsonPath('note', null)->assertJsonPath('updatedAt', null)
            ->assertJsonPath('createdAt', '2026-11-17T09:00:00');
        $this->assertCount(19, $response->json());
        $this->assertSame(0, DoctorSchedule::first()->version_number);
    }

    public function test_roles_and_authentication_match_controller_annotations(): void
    {
        $this->getJson('/api/doctor-schedules')->assertUnauthorized();
        foreach (['ADMIN', 'RECEPTIONIST', 'DOCTOR', 'PATIENT'] as $role) {
            $this->getJson('/api/doctor-schedules', $this->auth($role))->assertOk();
        }
        foreach (['DOCTOR', 'PATIENT', 'TECHNICIAN', 'PHARMACIST'] as $role) {
            $this->postJson('/api/doctor-schedules', $this->payload(), $this->auth($role))->assertForbidden();
        }
        $this->getJson('/api/doctor-schedules', $this->auth('TECHNICIAN'))->assertForbidden();
    }

    public function test_validation_and_parse_errors_keep_java_messages(): void
    {
        $this->postJson('/api/doctor-schedules', $this->payload(['doctorId' => null]), $this->auth())
            ->assertStatus(400)->assertExactJson(['doctorId' => 'Bác sĩ không được để trống']);
        $this->postJson('/api/doctor-schedules', $this->payload(['shift' => 'NIGHT']), $this->auth())
            ->assertStatus(400)->assertContent('Invalid request body. Please check JSON syntax and date format yyyy-MM-dd');
        $this->postJson('/api/doctor-schedules', $this->payload(['maxCapacity' => 100000]), $this->auth())
            ->assertStatus(400)->assertExactJson(['maxCapacity' => 'Sức chứa tối đa không được vượt quá 99999']);
    }

    public function test_duplicate_doctor_and_room_messages_are_preserved(): void
    {
        $this->schedule();
        $this->postJson('/api/doctor-schedules', $this->payload(), $this->auth())
            ->assertStatus(409)->assertContent('Bác sĩ đã có lịch ở ca này trong ngày đã chọn');
        $account = Account::create(['email' => 'other@person-one.test', 'role_id' => $this->account('DOCTOR')->role_id]);
        $doctor = Doctor::create(['account_id' => $account->account_id, 'full_name' => 'Other doctor', 'license_num' => 'OTHER']);
        $this->postJson('/api/doctor-schedules', $this->payload(['doctorId' => $doctor->doctor_id]), $this->auth())
            ->assertStatus(409)->assertContent('Phòng đã được phân công cho bác sĩ khác ở ca này trong ngày đã chọn');
    }

    public function test_update_runs_model_event_and_version_check_without_resetting_booking_counters(): void
    {
        $schedule = $this->schedule(['current_booking_count' => 2, 'last_queue_number' => 7]);
        $this->putJson('/api/doctor-schedules/'.$schedule->doctor_schedule_id, $this->payload(['maxCapacity' => 1]), $this->auth())
            ->assertStatus(400)->assertContent('Sức chứa tối đa không được nhỏ hơn số lượng đã đặt hiện tại');
        $this->putJson('/api/doctor-schedules/'.$schedule->doctor_schedule_id, $this->payload(['maxCapacity' => 8, 'note' => 'changed']), $this->auth())
            ->assertOk()->assertJsonPath('currentBookingCount', 2)->assertJsonPath('lastQueueNumber', 7)
            ->assertJsonPath('updatedAt', '2026-11-17T09:00:00');
        $this->assertSame(1, $schedule->fresh()->version_number);
    }

    public function test_stale_schedule_does_not_overwrite_another_update(): void
    {
        $schedule = $this->schedule();
        $other = $schedule->fresh();
        $repository = app(DoctorScheduleRepository::class);
        $schedule->note = 'winner';
        $repository->saveAndFlush($schedule);
        $other->note = 'stale';
        try {
            $repository->saveAndFlush($other);
            $this->fail('Expected an optimistic conflict');
        } catch (OptimisticLockException) {
            $this->assertSame('winner', $other->fresh()->note);
        }
    }

    public function test_delete_is_rejected_and_missing_schedule_still_returns_404(): void
    {
        $schedule = $this->schedule();
        $this->deleteJson('/api/doctor-schedules/'.$schedule->doctor_schedule_id, [], $this->auth())
            ->assertStatus(400)->assertContent('Không hỗ trợ xóa lịch làm việc bác sĩ');
        $this->deleteJson('/api/doctor-schedules/999', [], $this->auth())
            ->assertNotFound()->assertContent('Không tìm thấy lịch làm việc bác sĩ với id: 999');
        $this->assertNotNull($schedule->fresh());
    }

    public function test_account_id_fallback_reactivates_both_doctor_and_account(): void
    {
        $account = new Account(['email' => 'inactive@person-one.test', 'role_id' => $this->account('DOCTOR')->role_id, 'is_active' => 0]);
        $account->setAttribute('account_id', 9000);
        $account->save();
        $doctor = Doctor::create(['account_id' => 9000, 'full_name' => 'Inactive doctor', 'license_num' => 'INACTIVE', 'is_active' => 0]);
        $this->postJson('/api/doctor-schedules', $this->payload(['doctorId' => 9000]), $this->auth())
            ->assertCreated()->assertJsonPath('doctorId', $doctor->doctor_id);
        $this->assertSame(1, $doctor->fresh()->is_active);
        $this->assertSame(1, $account->fresh()->is_active);
    }

    public function test_listing_sorts_date_ascending_then_enum_ordinal_and_applies_filters(): void
    {
        $later = $this->schedule(['schedule_date' => '2026-11-19']);
        $afternoon = $this->schedule(['shift' => 'AFTERNOON']);
        $morning = $this->schedule();
        $response = $this->getJson('/api/doctor-schedules?startDate=2026-11-18&endDate=2026-11-19&doctorId='.$this->doctor->doctor_id, $this->auth());
        $response->assertOk();
        $this->assertSame([$morning->doctor_schedule_id, $afternoon->doctor_schedule_id, $later->doctor_schedule_id], array_column($response->json(), 'doctorScheduleId'));
    }

    public function test_empty_and_header_only_excel_files_match_java(): void
    {
        $this->post('/api/doctor-schedules/import', ['file' => UploadedFile::fake()->create('empty.xlsx', 0)], $this->auth())
            ->assertStatus(400)->assertContent('File tải lên trống');
        $file = $this->spreadsheet([['Doctor', 'Room', 'Date', 'Shift']]);
        $this->post('/api/doctor-schedules/import', ['file' => $file], $this->auth())
            ->assertCreated()->assertExactJson(['createdCount' => 0, 'schedules' => []]);
    }

    public function test_excel_import_keeps_zero_capacity_quirk_and_rolls_back_on_database_check(): void
    {
        $file = $this->spreadsheet([['Doctor', 'Room', 'Date', 'Shift'], [' doctor one ', ' test-room ', '18/11/2026', 'morning']]);
        $response = $this->post('/api/doctor-schedules/import', ['file' => $file], $this->auth());
        $response->assertStatus(400);
        $this->assertStringStartsWith('Lỗi khi đọc file Excel: ', $response->getContent());
        $this->assertSame(0, DoctorSchedule::count());
    }

    public function test_excel_import_reports_unknown_doctor_with_row_number(): void
    {
        $file = $this->spreadsheet([['Doctor', 'Room', 'Date', 'Shift'], ['Missing', 'TEST-ROOM', '2026-11-18', 'MORNING']]);
        $this->post('/api/doctor-schedules/import', ['file' => $file], $this->auth())
            ->assertStatus(400)->assertContent('Không tìm thấy bác sĩ đang hoạt động với tên: Missing ở dòng 2');
    }

    public function test_invalid_query_is_a_java_500_conversion_error(): void
    {
        $this->getJson('/api/doctor-schedules?doctorId=abc', $this->auth())->assertStatus(500)
            ->assertContent('An unexpected error occurred: Method parameter \'doctorId\': Failed to convert value of type \'java.lang.String\' to required type \'java.lang.Long\'; For input string: "abc"');
        $this->getJson('/api/doctor-schedules?date=2026-02-30', $this->auth())->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8');
    }

    public function test_excel_date_uses_java_smart_month_end_resolution(): void
    {
        $repository = $this->createMock(DoctorScheduleRepository::class);
        $repository->method('saveAndFlush')->willReturnCallback(function (DoctorSchedule $schedule) {
            $this->assertSame('2028-02-29', $schedule->getAttribute('schedule_date')->format('Y-m-d'));
            $this->assertSame(0, $schedule->max_capacity);

            return $schedule;
        });
        $this->app->instance(DoctorScheduleRepository::class, $repository);
        $file = $this->spreadsheet([['Doctor', 'Room', 'Date', 'Shift'], ['Doctor One', 'TEST-ROOM', '31/02/2028', 'morning']]);
        $this->post('/api/doctor-schedules/import', ['file' => $file], $this->auth())
            ->assertCreated()->assertJsonPath('createdCount', 1)->assertJsonPath('schedules.0.scheduleDate', '2028-02-29');
    }
}
