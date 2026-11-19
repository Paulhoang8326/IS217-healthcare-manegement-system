<?php

namespace Tests\Shared;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ConsultationFee;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Specialty;
use App\Shared\Auth\JwtServiceImpl;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

abstract class PersonOneModuleTestCase extends TestCase
{
    private static ?PDO $fixturePdo = null;

    protected Doctor $doctor;

    protected Room $room;

    protected Patient $patient;

    protected ConsultationFee $fee;

    private array $accounts = [];

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'healthcare_test') {
            throw new RuntimeException('Module integration tests require the dedicated healthcare_test MySQL database');
        }
        parent::setUp();
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            self::$fixturePdo = DB::connection()->getPdo();
        }
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00'));
        config(['jwt.secret' => 'person-one-test-secret', 'jwt.access-token-expiration-ms' => 60000,
            'sepay.webhook-secret' => 'webhook-test-secret', 'sepay.account-number' => '123456']);
        $specialty = Specialty::create(['specialty_code' => 'TEST', 'specialty_name' => 'Test specialty']);
        $doctorAccount = $this->account('DOCTOR');
        $this->doctor = Doctor::create(['account_id' => $doctorAccount->account_id, 'full_name' => 'Doctor One',
            'specialty_id' => $specialty->specialty_id, 'license_num' => 'TEST-DOCTOR-1']);
        $branch = Branch::create(['branch_name' => 'Test branch', 'branch_address' => 'Test address']);
        $roomType = RoomType::create(['room_type_name' => 'Consultation']);
        $this->room = Room::create(['room_code' => 'TEST-ROOM', 'room_type_id' => $roomType->room_type_id, 'branch_id' => $branch->branch_id]);
        $this->patient = Patient::create(['full_name' => 'Patient One']);
        $this->fee = ConsultationFee::create(['fee_code' => 'TEST-FEE', 'fee_name' => 'Consultation',
            'specialty' => 'Test specialty', 'specialty_id' => $specialty->specialty_id, 'price' => '150000.00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixturePdo !== null) {
            // Remove this class's migrated schema before Shared tests create their isolated fixtures.
            $connection = new MySqlConnection(self::$fixturePdo, 'healthcare_test');
            $connection->getSchemaBuilder()->dropAllTables();
            $connection->disconnect();
            self::$fixturePdo = null;
            RefreshDatabaseState::$migrated = false;
        }
        parent::tearDownAfterClass();
    }

    protected function account(string $role): Account
    {
        return $this->accounts[$role] ??= Account::create([
            'email' => strtolower($role).'@person-one.test', 'role_id' => Role::firstOrCreate(['role_name' => $role])->role_id,
        ]);
    }

    protected function auth(string $role = 'ADMIN'): array
    {
        $account = $this->account($role);

        return ['Authorization' => 'Bearer '.app(JwtServiceImpl::class)->generateToken($account->account_id, $account->email, $role)];
    }

    protected function schedule(array $overrides = []): DoctorSchedule
    {
        return DoctorSchedule::create($overrides + [
            'doctor_id' => $this->doctor->doctor_id, 'room_id' => $this->room->room_id,
            'schedule_date' => '2026-11-18', 'shift' => 'MORNING', 'max_capacity' => 5,
        ]);
    }

    protected function booking(DoctorSchedule $schedule, array $overrides = []): array
    {
        return $overrides + ['patientId' => $this->patient->patient_id, 'doctorScheduleId' => $schedule->doctor_schedule_id,
            'initialSymptoms' => 'Headache', 'visitReason' => 'Consultation'];
    }

    protected function spreadsheet(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hms-schedule-');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, 'schedules.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
