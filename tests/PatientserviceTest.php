<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../patient/PatientService.php';

/**
 * PatientServiceTest
 *
 * Unit tests for PatientService using a mocked PDO, so no real
 * database connection is required.
 *
 * Mocking strategy mirrors DoctorServiceTest/InsuranceServiceTest:
 * the constructor's ensureTables() fires several exec() calls, so
 * every test's PDO mock stubs exec() to return 0 by default.
 */
class PatientServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('exec')->willReturn(0);
    }

    private function makeStatement(bool $executeReturn = true, $fetchReturn = null, $fetchAllReturn = null, $fetchColumnReturn = null): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn($executeReturn);

        if ($fetchReturn !== null) {
            $stmt->method('fetch')->willReturn($fetchReturn);
        }
        if ($fetchAllReturn !== null) {
            $stmt->method('fetchAll')->willReturn($fetchAllReturn);
        }
        if ($fetchColumnReturn !== null) {
            $stmt->method('fetchColumn')->willReturn($fetchColumnReturn);
        }

        return $stmt;
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    public function testConstructorRunsEnsureTablesWithoutError(): void
    {
        $this->pdo->expects($this->atLeastOnce())->method('exec');
        $service = new PatientService($this->pdo);
        $this->assertInstanceOf(PatientService::class, $service);
    }

    public function testConstructorSurvivesAlterTableExceptions(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('exec')->willReturnCallback(function ($sql) {
            if (str_starts_with($sql, 'ALTER TABLE')) {
                throw new PDOException('Duplicate column name');
            }
            return 0;
        });

        $service = new PatientService($this->pdo);
        $this->assertInstanceOf(PatientService::class, $service);
    }

    // ------------------------------------------------------------------
    // Patient profile lookup
    // ------------------------------------------------------------------

    public function testGetPatientByEmailReturnsRowWhenFound(): void
    {
        $row = ['id' => 1, 'name' => 'David Bwashi', 'national_id' => 'OP02345'];
        $stmt = $this->makeStatement(true, $row);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($row, $service->getPatientByEmail('islaehrk@gmail.com'));
    }

    public function testGetPatientByEmailReturnsNullWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertNull($service->getPatientByEmail('nobody@example.com'));
    }

    public function testGetPatientByIdReturnsRowWhenFound(): void
    {
        $row = ['id' => 1, 'name' => 'David Bwashi'];
        $stmt = $this->makeStatement(true, $row);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($row, $service->getPatientById(1));
    }

    public function testGetPatientByIdReturnsNullWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertNull($service->getPatientById(999));
    }

    // ------------------------------------------------------------------
    // Access requests
    // ------------------------------------------------------------------

    public function testGetPendingAccessRequestsReturnsRows(): void
    {
        $rows = [
            ['id' => 5, 'hospital_admin_name' => 'Dr. Peliot B', 'medical_facility' => 'Kenyatta National Hospital'],
        ];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($rows, $service->getPendingAccessRequests(1));
    }

    public function testGetPendingAccessRequestsReturnsEmptyArrayWhenNoneExist(): void
    {
        $stmt = $this->makeStatement(true, null, []);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame([], $service->getPendingAccessRequests(1));
    }

    public function testProcessAccessRequestRejectsInvalidId(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new PatientService($this->pdo);

        $result = $service->processAccessRequest(0, 'approved');

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid access request action.', $result['message']);
    }

    public function testProcessAccessRequestRejectsInvalidAction(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new PatientService($this->pdo);

        $result = $service->processAccessRequest(5, 'maybe');

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid access request action.', $result['message']);
    }

    public function testProcessAccessRequestApprovesSuccessfully(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['approved', 5])->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $result = $service->processAccessRequest(5, 'approved');

        $this->assertTrue($result['success']);
        $this->assertSame('Access request has been approved.', $result['message']);
    }

    public function testProcessAccessRequestDeclinesSuccessfully(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['declined', 5])->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $result = $service->processAccessRequest(5, 'declined');

        $this->assertTrue($result['success']);
        $this->assertSame('Access request has been declined.', $result['message']);
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    private function baseRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'David Bwashi',
            'national_id' => 'OP02345',
            'email' => 'islaehrk@gmail.com',
            'password' => 'SecurePass123',
            'confirm_password' => 'SecurePass123',
            'dob' => '2003-09-29',
            'gender' => 'Male',
            'blood_group' => 'A+',
            'phone' => '+254758890381',
        ], $overrides);
    }

    public function testRegisterPatientFailsWhenPasswordsDoNotMatch(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new PatientService($this->pdo);

        $result = $service->registerPatient($this->baseRegistrationData(['confirm_password' => 'Different123']));

        $this->assertFalse($result['success']);
        $this->assertSame('Passwords do not match!', $result['message']);
    }

    public function testRegisterPatientFailsWhenAccountAlreadyExists(): void
    {
        $stmt = $this->makeStatement(true, ['id' => 1]);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $result = $service->registerPatient($this->baseRegistrationData());

        $this->assertFalse($result['success']);
        $this->assertSame('An account with this email or National ID already exists.', $result['message']);
    }

    public function testRegisterPatientSucceedsAndReturnsOtpAndId(): void
    {
        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->method('execute')->willReturn(true);
        $checkStmt->method('fetch')->willReturn(false);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($checkStmt, $insertStmt) {
            return str_contains($sql, 'SELECT id FROM patients') ? $checkStmt : $insertStmt;
        });
        $this->pdo->method('lastInsertId')->willReturn('42');

        $service = new PatientService($this->pdo);
        $result = $service->registerPatient($this->baseRegistrationData());

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['patient_id']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['otp_code']);
    }

    public function testRegisterPatientReturnsFailureMessageOnPdoException(): void
    {
        $this->pdo->method('prepare')->willThrowException(new PDOException('Connection lost'));

        $service = new PatientService($this->pdo);
        $result = $service->registerPatient($this->baseRegistrationData());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('System registration error:', $result['message']);
    }

    // ------------------------------------------------------------------
    // Medical records / prescriptions / privacy consents
    // ------------------------------------------------------------------

    public function testGetMedicalRecordsReturnsRows(): void
    {
        $rows = [['id' => 1, 'visit_type' => 'Consultation']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($rows, $service->getMedicalRecords(1));
    }

    public function testGetPrescriptionsReturnsRows(): void
    {
        $rows = [['medication_name' => 'Amoxicillin']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($rows, $service->getPrescriptions(1));
    }

    public function testGetPrivacyConsentsReturnsStoredRow(): void
    {
        $row = [
            'allergies_summary_text' => 'Penicillin allergy noted.',
            'chronic_diagnostic_logs_text' => 'None.',
            'surgical_typologies_summary_text' => 'None.',
            'surgical_typologies_necessary' => 0,
            'authored_by_doctor_name' => 'Dr. Peliot B',
            'updated_at' => '2026-07-01 10:00:00',
        ];
        $stmt = $this->makeStatement(true, $row);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($row, $service->getPrivacyConsents(1));
    }

    public function testGetPrivacyConsentsReturnsPatientFacingDefaultWhenNoneExists(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $result = $service->getPrivacyConsents(1);

        $this->assertSame('No doctor summary provided yet.', $result['allergies_summary_text']);
        $this->assertSame('No doctor summary provided yet.', $result['chronic_diagnostic_logs_text']);
        $this->assertSame('No doctor summary provided yet.', $result['surgical_typologies_summary_text']);
        $this->assertSame(0, $result['surgical_typologies_necessary']);
        $this->assertNull($result['authored_by_doctor_name']);
    }

    // ------------------------------------------------------------------
    // Vitals
    // ------------------------------------------------------------------

    public function testGetLatestVitalsRecordReturnsRowWhenFound(): void
    {
        $row = ['visit_type' => 'Routine Check-up', 'blood_pressure' => '120/80', 'heart_rate' => '72', 'temperature' => '36.6'];
        $stmt = $this->makeStatement(true, $row);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame($row, $service->getLatestVitalsRecord(1));
    }

    public function testGetLatestVitalsRecordReturnsNullWhenNoneFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertNull($service->getLatestVitalsRecord(1));
    }

    public function testGetLatestMetricValueReturnsValueWhenFound(): void
    {
        $stmt = $this->makeStatement(true, null, null, '120/80');
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertSame('120/80', $service->getLatestMetricValue(1, 'blood_pressure'));
    }

    public function testGetLatestMetricValueReturnsNullWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, null, null, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new PatientService($this->pdo);
        $this->assertNull($service->getLatestMetricValue(1, 'heart_rate'));
    }

    public function testGetLatestMetricValueThrowsOnUnsupportedColumn(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new PatientService($this->pdo);

        $this->expectException(InvalidArgumentException::class);
        $service->getLatestMetricValue(1, 'password');
    }
}