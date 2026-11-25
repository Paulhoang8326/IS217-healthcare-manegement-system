<?php

namespace Tests\Feature\Database;

use App\Enums\AdmissionStatus;
use App\Enums\AppointmentStatus;
use App\Enums\LabTestRequestStatus;
use App\Enums\MedicalRecordStatus;
use App\Enums\MedicalServiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Account;
use App\Models\AccountPermission;
use App\Models\AdmissionRecord;
use App\Models\AdmissionRequest;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Branch;
use App\Models\ConsultationFee;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\LabTest;
use App\Models\LabTestRequest;
use App\Models\LabTestRequestItem;
use App\Models\LabTestResult;
use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\MedicalServiceRequest;
use App\Models\MedicalServiceRequestItem;
use App\Models\MedicalServiceResult;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\PaymentRecord;
use App\Models\PaymentTransaction;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\PrescriptionDetail;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Specialty;
use App\Models\Technician;
use App\Shared\Exceptions\OptimisticLockException;
use App\Shared\Support\OptimisticLock;
use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK = 3819;

    private const FOREIGN_KEY = 1452;

    private const DUPLICATE = 1062;

    private const NOT_NULL = 1048;

    private static ?PDO $fixturePdo = null;

    private Patient $patient;

    private Doctor $doctor;

    private Room $room;

    private DoctorSchedule $schedule;

    private Appointment $appointment;

    private MedicalRecord $record;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'healthcare_test') {
            throw new RuntimeException('Database tests require the dedicated healthcare_test MySQL database');
        }
        parent::setUp();
        self::$fixturePdo = DB::connection()->getPdo();
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00'));

        $role = Role::create(['role_name' => 'DOCTOR']);
        $account = Account::create(['email' => 'doctor@schema.test', 'role_id' => $role->role_id]);
        $this->doctor = Doctor::create(['account_id' => $account->account_id, 'full_name' => 'Doctor', 'license_num' => 'LIC-1']);
        $this->patient = Patient::create(['full_name' => 'Patient', 'identity_num' => 'ID-1']);
        $this->room = Room::create([
            'room_code' => 'R-1',
            'room_type_id' => RoomType::create(['room_type_name' => 'Clinic'])->room_type_id,
            'branch_id' => Branch::create(['branch_name' => 'Branch', 'branch_address' => 'Address'])->branch_id,
        ]);
        $this->schedule = DoctorSchedule::create([
            'doctor_id' => $this->doctor->doctor_id, 'room_id' => $this->room->room_id,
            'schedule_date' => '2026-11-18', 'shift' => 'MORNING', 'max_capacity' => 5,
        ]);
        $this->appointment = $this->appointment('APT-1');
        $this->record = MedicalRecord::create([
            'appointment_id' => $this->appointment->appointment_id, 'doctor_id' => $this->doctor->doctor_id,
            'patient_id' => $this->patient->patient_id, 'initial_diagnosis' => 'Flu',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixturePdo !== null) {
            // Shared tests create their own tables with the same names.
            $connection = new MySqlConnection(self::$fixturePdo, 'healthcare_test');
            $connection->getSchemaBuilder()->dropAllTables();
            $connection->disconnect();
            self::$fixturePdo = null;
            RefreshDatabaseState::$migrated = false;
        }
        parent::tearDownAfterClass();
    }

    public function test_seeders_insert_the_java_master_data_in_order(): void
    {
        DB::table('medical_record')->delete();
        DB::table('appointment')->delete();
        DB::table('doctor_schedule')->delete();
        DB::table('doctor')->delete();
        DB::table('account')->delete();
        DB::table('role')->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['ADMIN', 'DOCTOR', 'PATIENT', 'RECEPTIONIST', 'TECHNICIAN', 'PHARMACIST', 'ACCOUNTANT'],
            Role::orderBy('role_id')->pluck('role_name')->all(),
        );
        $this->assertSame(
            ['Paracetamol 500mg', 'Amoxicillin 500mg', 'Ibuprofen 400mg', 'Decolgen Forte', 'Panadol Extra',
                'Cetirizine 10mg', 'Salbutamol 2mg', 'Vitamin C 500mg'],
            Medicine::orderBy('medicine_id')->pluck('medicine_name')->all(),
        );
        $this->assertSame('admin123', Account::where('email', 'admin@gmail.com')->value('password_hash'));
        $this->assertSame(0, MedicineLot::count() + ConsultationFee::count() + DoctorSchedule::count() + MedicalRecord::count());
    }

    public function test_version_columns_are_signed_bigint_not_null_default_zero(): void
    {
        foreach (['appointment', 'doctor_schedule', 'medical_record'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'version_number');
            $this->assertSame(['bigint', false, '0'], [$column['type'], $column['nullable'], $column['default']], $table);
        }
    }

    public function test_clob_columns_hold_more_than_64_kilobytes(): void
    {
        $text = str_repeat('a', 70000);
        $this->patient->update(['medical_history' => $text]);
        $this->appointment->update(['initial_symptoms' => $text]);
        $this->record->update(['clinical_conclusion' => $text, 'clinical_notes' => $text, 'treatment_plan' => $text]);
        $prescription = $this->prescription(['note' => $text]);
        $lab = LabTestResult::create(['lab_test_request_id' => $this->labRequest('L1')->lab_test_request_id, 'result_data' => $text]);
        $service = MedicalServiceResult::create(['med_ser_req_id' => $this->serviceRequest('S1')->med_ser_req_id, 'result_data' => $text]);
        $transaction = $this->transaction(['raw_data' => $text]);

        $stored = [
            $this->patient->refresh()->medical_history, $this->appointment->refresh()->initial_symptoms,
            $this->record->refresh()->clinical_conclusion, $this->record->clinical_notes, $this->record->treatment_plan,
            $prescription->refresh()->note, $lab->refresh()->result_data, $service->refresh()->result_data,
            $transaction->refresh()->raw_data,
        ];
        foreach ($stored as $value) {
            $this->assertSame(70000, strlen($value));
        }
    }

    public function test_number_precision_matches_oracle(): void
    {
        $bed = $this->bed();
        $admission = $this->admission($bed);
        $vitals = fn (int $rate) => AdmissionRecord::create([
            'admission_id' => $admission->admission_id, 'blood_pressure' => '120/80', 'heart_rate' => $rate, 'temperature' => 37,
        ]);
        $vitals(99999);
        $this->assertDatabaseError(self::CHECK, fn () => $vitals(100000));

        $account = Account::create(['email' => 'tech@schema.test', 'role_id' => Role::firstOrFail()->role_id]);
        $technician = Technician::create(['account_id' => $account->account_id, 'full_name' => 'Tech', 'experience' => 999]);
        $this->assertDatabaseError(self::CHECK, fn () => $technician->update(['experience' => 1000]));
        $this->assertDatabaseError(self::CHECK, fn () => $this->schedule->update(['max_capacity' => 100000]));
        $this->assertDatabaseError(self::CHECK, fn () => $this->appointment->update(['queue_num' => 100000]));
        $this->assertDatabaseError(self::CHECK, fn () => Medicine::create(['medicine_name' => 'M', 'unit' => 'U', 'is_active' => 10]));
    }

    public function test_unset_fields_store_null_like_hibernate(): void
    {
        $this->assertNull($this->admission($this->bed())->refresh()->total_price);
        $this->assertNull($this->labRequest('L1')->refresh()->total_price);
        $this->assertNull($this->serviceRequest('S1')->refresh()->total_price);

        $medicine = Medicine::create(['medicine_name' => 'M', 'unit' => 'U']);
        $this->assertDatabaseError(self::NOT_NULL, fn () => MedicineLot::create([
            'medicine_id' => $medicine->medicine_id, 'lot_number' => 'LOT', 'expiry_date' => '2027-01-01',
        ]));
    }

    public function test_request_status_default_stays_outside_the_check(): void
    {
        foreach (['lab_test_request', 'medical_service_request'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'status');
            $this->assertStringContainsString('PENDING', $column['default']);
            $this->assertDatabaseError(self::CHECK, fn () => DB::table($table)->insert([
                'med_record_id' => $this->record->med_record_id, 'request_code' => 'RAW',
            ]));
        }
    }

    public function test_payment_record_has_exactly_one_owner(): void
    {
        $owners = [
            'appointment_id' => $this->appointment->appointment_id,
            'med_record_id' => $this->record->med_record_id,
            'prescription_id' => $this->prescription()->prescription_id,
        ];
        foreach ($owners as $column => $id) {
            PaymentRecord::create([$column => $id, 'request_code' => $column, 'total_price' => 1]);
            foreach (array_diff_key($owners, [$column => 1]) as $other => $otherId) {
                $this->assertDatabaseError(self::CHECK, fn () => DB::table('payment_record')->insert([
                    $column => $id, $other => $otherId, 'request_code' => "$column-$other", 'total_price' => 1,
                ]));
            }
        }
        $this->assertDatabaseError(self::CHECK, fn () => DB::table('payment_record')->insert(['request_code' => 'NONE', 'total_price' => 1]));
        $this->assertDatabaseError(self::DUPLICATE, fn () => PaymentRecord::create([
            'appointment_id' => $owners['appointment_id'], 'request_code' => 'AGAIN', 'total_price' => 1,
        ]));
    }

    public function test_partial_is_accepted_only_by_payment_record(): void
    {
        $payment = PaymentRecord::create([
            'med_record_id' => $this->record->med_record_id, 'request_code' => 'P', 'total_price' => 2,
            'payment_status' => PaymentStatus::PARTIAL,
        ]);
        $this->assertSame(PaymentStatus::PARTIAL, $payment->refresh()->payment_status);
        $this->assertDatabaseError(self::CHECK, fn () => $this->labRequest('L1', ['payment_status' => PaymentStatus::PARTIAL]));
        $this->assertDatabaseError(self::CHECK, fn () => $this->serviceRequest('S1', ['payment_status' => PaymentStatus::PARTIAL]));
    }

    public function test_unique_foreign_key_and_not_null_constraints(): void
    {
        $base = ['doctor_id' => $this->doctor->doctor_id, 'room_id' => $this->room->room_id,
            'schedule_date' => '2026-11-18', 'shift' => 'MORNING', 'max_capacity' => 1];
        $this->assertDatabaseError(self::DUPLICATE, fn () => DoctorSchedule::create($base));
        $this->assertDatabaseError(self::CHECK, fn () => DB::table('doctor_schedule')->insert(['shift' => 'NIGHT'] + $base));
        $this->assertDatabaseError(self::FOREIGN_KEY, fn () => DoctorSchedule::create(['doctor_id' => 999999, 'room_id' => $this->room->room_id, 'shift' => 'AFTERNOON'] + $base));

        $this->appointment->update(['queue_num' => 1]);
        $this->assertDatabaseError(self::DUPLICATE, fn () => $this->appointment('APT-2', ['queue_num' => 1]));
        $this->appointment('APT-3');
        $this->appointment('APT-4');

        $specialty = Specialty::create(['specialty_code' => 'S', 'specialty_name' => 'Specialty']);
        foreach (['F1', 'F2'] as $code) {
            ConsultationFee::create(['fee_code' => $code, 'fee_name' => $code, 'specialty' => 'Specialty',
                'specialty_id' => $specialty->specialty_id, 'price' => 1]);
        }
        $this->assertSame(2, ConsultationFee::where('specialty_id', $specialty->specialty_id)->count());
        $this->assertDatabaseError(self::NOT_NULL, fn () => Branch::create(['branch_name' => null, 'branch_address' => 'A']));
        $this->assertDatabaseError(self::CHECK, fn () => PatientInsurance::create(['patient_id' => $this->patient->patient_id,
            'insurance_num' => 'I', 'expiry_date' => '2027-01-01', 'coverage_percent' => 101]));
        $this->assertDatabaseError(1451, fn () => $this->patient->delete());
    }

    public function test_null_unique_values_repeat(): void
    {
        Patient::create(['full_name' => 'A']);
        Patient::create(['full_name' => 'B']);
        $this->assertSame(2, Patient::whereNull('identity_num')->whereNull('account_id')->count());
    }

    /**
     * Records how MySQL compares today. Oracle treats each pair as different values and '' as NULL;
     * update this test together with the collation decision.
     */
    public function test_comparisons_are_binary_like_oracle(): void
    {
        $names = ['e', 'z', 'a', 'b', 'A', 'B', 'Á', 'đ'];
        foreach ([...$names, 'A '] as $name) {
            Branch::create(['branch_name' => $name, 'branch_address' => 'X']);
        }

        $this->assertDatabaseError(self::DUPLICATE, fn () => Branch::create(['branch_name' => 'A', 'branch_address' => 'X']));
        $this->assertSame(['A', 'B', 'a', 'b', 'e', 'z', 'Á', 'đ'], Branch::whereIn('branch_name', $names)->orderBy('branch_name')->pluck('branch_name')->all());
        $this->assertSame(['Á'], Branch::where('branch_name', 'Á')->pluck('branch_name')->all());
        $this->assertSame(['A', 'a'], Branch::whereRaw('UPPER(branch_name) = UPPER(?)', ['a'])->orderBy('branch_name')->pluck('branch_name')->all());
        $this->assertDatabaseError(self::CHECK, fn () => $this->patient->update(['gender' => 'male']));
        $this->assertDatabaseError(self::CHECK, fn () => $this->patient->update(['gender' => 'MALE ']));
    }

    public function test_every_text_column_of_the_schema_is_binary(): void
    {
        $tables = array_map(
            fn (string $file) => (new ('App\\Models\\'.basename($file, '.php')))->getTable(),
            array_filter(glob(app_path('Models/*.php')) ?: [], fn (string $file) => basename($file) !== 'User.php'),
        );
        $this->assertCount(38, $tables);

        $collations = DB::table('information_schema.columns')->where('table_schema', DB::getDatabaseName())
            ->whereIn('table_name', $tables)->whereNotNull('collation_name')->distinct()->pluck('collation_name as collation')->all();
        $this->assertSame(['utf8mb4_0900_bin'], $collations);
    }

    public function test_empty_strings_are_stored_as_null_like_oracle(): void
    {
        $patient = Patient::create(['full_name' => 'B', 'identity_num' => '', 'medical_history' => '']);
        Patient::create(['full_name' => 'C', 'identity_num' => '']);
        $this->assertSame('', $patient->identity_num);
        $this->assertNull($patient->refresh()->identity_num);
        $this->assertNull($patient->medical_history);

        $patient->update(['phone' => '']);
        $this->assertSame('', $patient->phone);
        $this->assertNull($patient->refresh()->phone);
        $patient->update(['full_name' => ' ']);
        $this->assertSame(' ', $patient->refresh()->full_name);
        $this->assertDatabaseError(self::NOT_NULL, fn () => $patient->update(['full_name' => '']));

        $this->appointment->visit_reason = '';
        OptimisticLock::update('appointment', 'appointment_id', $this->appointment->appointment_id, 0, $this->appointment->getDirty());
        $this->assertNull($this->appointment->refresh()->visit_reason);
    }

    public function test_creating_events_match_pre_persist(): void
    {
        $now = '2026-11-17 09:00:00';
        $lab = $this->labRequest('L1', ['created_at' => '2000-01-01 00:00:00']);
        $service = $this->serviceRequest('S1', ['created_at' => '2000-01-01 00:00:00']);
        $prescription = $this->prescription();
        $transaction = $this->transaction();
        $admission = $this->admission($this->bed());
        $medicine = Medicine::create(['medicine_name' => 'M', 'unit' => 'U']);
        $lot = MedicineLot::create(['medicine_id' => $medicine->medicine_id, 'lot_number' => 'LOT', 'expiry_date' => '2027-01-01', 'quantity' => 1]);

        $this->assertSame(AppointmentStatus::PENDING, $this->appointment->refresh()->status);
        $this->assertSame($now, $this->appointment->created_at->toDateTimeString());
        $this->assertNull($this->appointment->updated_at);
        $this->assertSame([0, 0, 0], [$this->schedule->refresh()->current_booking_count, $this->schedule->last_queue_number, $this->schedule->version_number]);
        $this->assertSame([MedicalRecordStatus::DRAFT, '0.00'], [$this->record->refresh()->status, $this->record->total_price]);
        $this->assertSame([LabTestRequestStatus::NOT_COLLECTED, PaymentStatus::UNPAID], [$lab->refresh()->status, $lab->payment_status]);
        // LabTestRequest always overwrites createdAt, MedicalServiceRequest keeps a given value.
        $this->assertSame($now, $lab->created_at->toDateTimeString());
        $this->assertSame('2000-01-01 00:00:00', $service->refresh()->created_at->toDateTimeString());
        $this->assertSame([MedicalServiceRequestStatus::NOT_COLLECTED, 'VND'], [$service->status, $service->currency]);
        $this->assertSame(1, $prescription->refresh()->is_active);
        $this->assertSame(PaymentTransactionStatus::PENDING, $transaction->refresh()->process_status);
        $this->assertSame($now, $transaction->transaction_date->toDateTimeString());
        $this->assertSame(AdmissionStatus::PENDING, $admission->refresh()->status);
        $this->assertSame('2026-11-17', $lot->refresh()->import_date->toDateString());
    }

    public function test_updating_events_match_pre_update(): void
    {
        $models = [
            [$this->appointment, ['visit_reason' => 'x']], [$this->schedule, ['note' => 'x']], [$this->record, ['clinical_notes' => 'x']],
            [$this->labRequest('L1'), ['note' => 'x']], [$this->serviceRequest('S1'), ['note' => 'x']], [$this->prescription(), ['note' => 'x']],
            [PaymentRecord::create(['med_record_id' => $this->record->med_record_id, 'request_code' => 'P', 'total_price' => 1]), ['total_price' => 2]],
            [Specialty::create(['specialty_code' => 'S', 'specialty_name' => 'S']), ['specialty_name' => 'x']],
            [ConsultationFee::create(['fee_code' => 'F', 'fee_name' => 'F', 'specialty' => 'S', 'price' => 1]), ['fee_name' => 'x']],
        ];
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        foreach ($models as [$model, $changes]) {
            $this->assertNull($model->updated_at, $model::class);
            $model->save();
            $this->assertNull($model->refresh()->updated_at, $model::class);
            $model->update($changes);
            $this->assertSame('2026-11-18 10:00:00', $model->refresh()->updated_at->toDateTimeString(), $model::class);
            $this->assertSame('2026-11-17 09:00:00', $model->created_at->toDateTimeString(), $model::class);
        }
    }

    #[DataProvider('compositeModels')]
    public function test_composite_keys_touch_only_their_own_row(string $class): void
    {
        [$first, $second, $keys] = $this->compositeRows($class);
        $this->assertSame($keys[1], array_intersect_key($class::findByCompositeKey($keys[1])->getAttributes(), $keys[1]));
        $this->assertSame($keys[0], array_intersect_key($first->refresh()->getAttributes(), $keys[0]));
        $this->assertSame($keys[1], array_intersect_key($second->fresh()->getAttributes(), $keys[1]));

        if ($first->isFillable('snapshot_price')) {
            $second->update(['snapshot_price' => '9.00']);
            $this->assertSame(['1.00', '9.00'], [$first->refresh()->snapshot_price, $second->refresh()->snapshot_price]);
        }

        $this->assertDatabaseError(self::DUPLICATE, fn () => $class::create($first->getAttributes()));
        $second->delete();
        $this->assertNull($class::findByCompositeKey($keys[1]));
        $this->assertNotNull($class::findByCompositeKey($keys[0]));
    }

    public static function compositeModels(): iterable
    {
        foreach ([RolePermission::class, AccountPermission::class, LabTestRequestItem::class, MedicalServiceRequestItem::class] as $class) {
            yield class_basename($class) => [$class];
        }
    }

    public function test_optimistic_lock_on_the_migrated_tables(): void
    {
        foreach ([[$this->appointment, 'visit_reason'], [$this->schedule, 'note'], [$this->record, 'clinical_notes']] as [$model, $column]) {
            $table = $model->getTable();
            $key = $model->getKeyName();
            $this->assertSame(1, OptimisticLock::update($table, $key, $model->getKey(), 0, [$column => 'x']));
            $this->assertSame(1, $model->refresh()->version_number);
            try {
                OptimisticLock::update($table, $key, $model->getKey(), 0, [$column => 'stale']);
                $this->fail("$table accepted a stale version");
            } catch (OptimisticLockException) {
                $this->assertSame('x', $model->refresh()->$column);
            }
            DB::table($table)->update(['version_number' => PHP_INT_MAX]);
            $this->assertSame(PHP_INT_MIN, OptimisticLock::update($table, $key, $model->getKey(), PHP_INT_MAX, [$column => 'wrap']));
            $this->assertSame(PHP_INT_MIN, $model->refresh()->version_number);
        }
    }

    public function test_relations_resolve_real_rows(): void
    {
        $prescription = $this->prescription();
        $detail = PrescriptionDetail::create(['prescription_id' => $prescription->prescription_id,
            'medicine_id' => Medicine::create(['medicine_name' => 'M', 'unit' => 'U'])->medicine_id,
            'dosage' => '1', 'frequency' => '1', 'duration' => '1', 'quantity' => 1]);
        $payment = PaymentRecord::create(['prescription_id' => $prescription->prescription_id, 'request_code' => 'P', 'total_price' => 1]);
        $bed = $this->bed();

        $this->assertTrue($this->record->appointment->is($this->appointment));
        $this->assertTrue($this->appointment->doctorSchedule->doctor->is($this->doctor));
        $this->assertTrue($prescription->paymentRecord->is($payment));
        $this->assertTrue($prescription->prescriptionDetails->first()->is($detail));
        $this->assertTrue($this->room->beds->first()->is($bed));
        $this->assertSame('DOCTOR', $this->doctor->account->role->role_name);
    }

    private function assertDatabaseError(int $code, Closure $action): void
    {
        try {
            DB::transaction($action);
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[1], $exception->getMessage());

            return;
        }
        $this->fail("Expected MySQL error $code");
    }

    private function appointment(string $code, array $overrides = []): Appointment
    {
        return Appointment::create($overrides + ['patient_id' => $this->patient->patient_id,
            'doctor_schedule_id' => $this->schedule->doctor_schedule_id, 'appointment_code' => $code]);
    }

    private function bed(): Bed
    {
        return Bed::create(['room_id' => $this->room->room_id, 'price' => 1]);
    }

    private function admission(Bed $bed): AdmissionRequest
    {
        return AdmissionRequest::create(['patient_id' => $this->patient->patient_id, 'med_record_id' => $this->record->med_record_id,
            'bed_id' => $bed->bed_id, 'admission_date' => '2026-11-17']);
    }

    private function labRequest(string $code, array $overrides = []): LabTestRequest
    {
        return LabTestRequest::create($overrides + ['med_record_id' => $this->record->med_record_id, 'request_code' => $code]);
    }

    private function serviceRequest(string $code, array $overrides = []): MedicalServiceRequest
    {
        return MedicalServiceRequest::create($overrides + ['med_record_id' => $this->record->med_record_id, 'request_code' => $code]);
    }

    private function prescription(array $overrides = []): Prescription
    {
        return Prescription::create($overrides + ['med_record_id' => $this->record->med_record_id]);
    }

    private function transaction(array $overrides = []): PaymentTransaction
    {
        $payment = PaymentRecord::create(['appointment_id' => $this->appointment->appointment_id, 'request_code' => 'TXN', 'total_price' => 1]);

        return PaymentTransaction::create($overrides + ['payment_record_id' => $payment->payment_record_id,
            'gateway' => 'CASH', 'transfer_amount' => 1]);
    }

    /**
     * @return array{Model, Model, list<array<string, int>>}
     */
    private function compositeRows(string $class): array
    {
        $permission = fn (string $name) => Permission::create(['permission_name' => $name])->permission_id;
        [$owner, $item, $extra] = match ($class) {
            RolePermission::class => [['role_id' => Role::firstOrFail()->role_id], 'permission_id', []],
            AccountPermission::class => [['account_id' => $this->doctor->account_id], 'permission_id', []],
            LabTestRequestItem::class => [['lab_test_request_id' => $this->labRequest('L')->lab_test_request_id], 'lab_test_id', ['snapshot_price' => '1.00']],
            MedicalServiceRequestItem::class => [['med_ser_req_id' => $this->serviceRequest('S')->med_ser_req_id], 'med_service_id', ['snapshot_price' => '1.00']],
        };
        $keys = array_map(fn (string $name) => $owner + [$item => match ($item) {
            'permission_id' => $permission($name),
            'lab_test_id' => LabTest::create(['lab_test_name' => $name, 'price' => 1])->lab_test_id,
            'med_service_id' => MedicalService::create(['medical_service_name' => $name, 'price' => 1])->med_service_id,
        }], ['one', 'two']);

        return [$class::create($keys[0] + $extra), $class::create($keys[1] + $extra), $keys];
    }
}
