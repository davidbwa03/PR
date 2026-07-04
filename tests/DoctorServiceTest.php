<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../doctor/DoctorService.php';

/**
 * DoctorServiceTest
 *
 * Unit tests for DoctorService using a mocked PDO/PDOStatement, so
 * these run without a real database connection.
 *
 * Notes on mocking strategy:
 *   - The constructor calls ensureTables(), which fires several
 *     CREATE TABLE / ALTER TABLE statements via $pdo->exec(). Every
 *     test stubs exec() to return 0 so construction never touches
 *     a real database.
 *   - Each read/write method calls $pdo->prepare() once, returning a
 *     PDOStatement mock whose execute()/fetch()/fetchAll()/fetchColumn()
 *     are stubbed per test.
 */
class DoctorServiceTest extends TestCase
{
    private PDO&MockObject $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        // Allow ensureTables() to run freely during construction.
        $this->pdo->method('exec')->willReturn(0);
    }

    /**
     * Helper: build a PDOStatement mock preloaded with a canned
     * execute() result and fetch()/fetchAll()/fetchColumn() return value.
     */
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
    // Construction / ensureTables
    // ------------------------------------------------------------------

    public function testConstructorRunsEnsureTablesWithoutError(): void
    {
        // exec() is expected to run at least once (CREATE TABLE calls).
        $this->pdo->expects($this->atLeastOnce())->method('exec');
        $service = new DoctorService($this->pdo);
        $this->assertInstanceOf(DoctorService::class, $service);
    }

    public function testConstructorSurvivesAlterTableExceptions(): void
    {
        // Simulate "column already exists" errors on the ALTER TABLE
        // guard statements; ensureTables() should swallow them silently.
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('exec')->willReturnCallback(function ($sql) {
            if (str_starts_with($sql, 'ALTER TABLE')) {
                throw new PDOException('Duplicate column name');
            }
            return 0;
        });

        $service = new DoctorService($this->pdo);
        $this->assertInstanceOf(DoctorService::class, $service);
    }

    // ------------------------------------------------------------------
    // Doctor profile
    // ------------------------------------------------------------------

    public function testGetDoctorReturnsRowWhenFound(): void
    {
        $doctorRow = ['id' => 3, 'name' => 'Peliot B', 'specialty' => 'Dentist'];
        $stmt = $this->makeStatement(true, $doctorRow);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getDoctor(3);

        $this->assertSame($doctorRow, $result);
    }

    public function testGetDoctorReturnsNullWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getDoctor(999);

        $this->assertNull($result);
    }

    public function testGetDoctorSpecialtyReturnsStoredValue(): void
    {
        $stmt = $this->makeStatement(true, null, null, 'Cardiologist');
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getDoctorSpecialty(1);

        $this->assertSame('Cardiologist', $result);
    }

    public function testGetDoctorSpecialtyFallsBackToDefaultWhenEmpty(): void
    {
        $stmt = $this->makeStatement(true, null, null, '');
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getDoctorSpecialty(1);

        $this->assertSame('General Practitioner', $result);
    }

    public function testGetDoctorSpecialtyRespectsCustomDefault(): void
    {
        $stmt = $this->makeStatement(true, null, null, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getDoctorSpecialty(1, 'Doctor');

        $this->assertSame('Doctor', $result);
    }

    // ------------------------------------------------------------------
    // Patient listing
    // ------------------------------------------------------------------

    public function testGetAssignedPatientsReturnsRows(): void
    {
        $rows = [
            ['patient_id' => 1, 'patient_name' => 'Jane Doe', 'record_count' => 2, 'allergy_count' => 1],
            ['patient_id' => 2, 'patient_name' => 'John Smith', 'record_count' => 0, 'allergy_count' => 0],
        ];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getAssignedPatients('Dr. Peliot B');

        $this->assertCount(2, $result);
        $this->assertSame($rows, $result);
    }

    public function testGetAssignedPatientsReturnsEmptyArrayWhenNoneAssigned(): void
    {
        $stmt = $this->makeStatement(true, null, []);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getAssignedPatients('Dr. Nobody');

        $this->assertSame([], $result);
    }

    public function testGetAssignedPatientsByDoctorIdReturnsRows(): void
    {
        $rows = [['id' => 1, 'name' => 'Jane Doe']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getAssignedPatientsByDoctorId(3);

        $this->assertSame($rows, $result);
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    public function testVerifyAccessByNameReturnsRowWhenApproved(): void
    {
        $row = ['id' => 5, 'doctor_name' => 'Dr. Peliot B', 'request_status' => 'approved', 'records_sent' => 1];
        $stmt = $this->makeStatement(true, $row);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->verifyAccessByName('Dr. Peliot B', 10);

        $this->assertSame($row, $result);
    }

    public function testVerifyAccessByNameReturnsNullWhenNotApproved(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->verifyAccessByName('Dr. Peliot B', 10);

        $this->assertNull($result);
    }

    public function testVerifyAccessByIdReturnsTrueWhenAccessGranted(): void
    {
        $stmt = $this->makeStatement(true, ['id' => 1]);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertTrue($service->verifyAccessById(3, 10));
    }

    public function testVerifyAccessByIdReturnsFalseWhenAccessDenied(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertFalse($service->verifyAccessById(3, 10));
    }

    public function testResolveFacilityNameReturnsResolvedValue(): void
    {
        $stmt = $this->makeStatement(true, null, null, 'Kenyatta National Hospital');
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->resolveFacilityName(3, 10);

        $this->assertSame('Kenyatta National Hospital', $result);
    }

    public function testResolveFacilityNameFallsBackWhenEmpty(): void
    {
        $stmt = $this->makeStatement(true, null, null, '');
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->resolveFacilityName(3, 10);

        $this->assertSame('Central Medical Center', $result);
    }

    public function testResolveFacilityNameFallsBackOnPdoException(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('exec')->willReturn(0);
        $this->pdo->method('prepare')->willThrowException(new PDOException('DB error'));

        $service = new DoctorService($this->pdo);
        $result = $service->resolveFacilityName(3, 10, 'Fallback Clinic');

        $this->assertSame('Fallback Clinic', $result);
    }

    // ------------------------------------------------------------------
    // Patient detail / records / prescriptions / allergies
    // ------------------------------------------------------------------

    public function testGetPatientReturnsRowWhenFound(): void
    {
        $patient = ['id' => 10, 'name' => 'Jane Doe', 'national_id' => '12345'];
        $stmt = $this->makeStatement(true, $patient);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getPatient(10);

        $this->assertSame($patient, $result);
    }

    public function testGetPatientReturnsNullWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertNull($service->getPatient(999));
    }

    public function testGetMedicalRecordsReturnsRows(): void
    {
        $rows = [['id' => 1, 'visit_type' => 'Consultation', 'diagnosis' => 'Flu']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertSame($rows, $service->getMedicalRecords(10));
    }

    public function testGetRecentMedicalRecordsReturnsRows(): void
    {
        $rows = [['visit_type' => 'Follow-up', 'visit_date' => '2026-06-01']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertSame($rows, $service->getRecentMedicalRecords(10, 5));
    }

    public function testGetPrescriptionsReturnsRows(): void
    {
        $rows = [['medication_name' => 'Amoxicillin', 'dosage' => '500mg']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertSame($rows, $service->getPrescriptions(10));
    }

    public function testGetRecentPrescriptionsReturnsRows(): void
    {
        $rows = [['medication_name' => 'Ibuprofen', 'dosage' => '200mg', 'frequency' => 'Daily']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertSame($rows, $service->getRecentPrescriptions(10, 5));
    }

    public function testGetAllergiesReturnsRows(): void
    {
        $rows = [['allergen_name' => 'Penicillin']];
        $stmt = $this->makeStatement(true, null, $rows);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertSame($rows, $service->getAllergies(10));
    }

    public function testGetAllergiesReturnsEmptyArrayWhenNoneRecorded(): void
    {
        $stmt = $this->makeStatement(true, null, []);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $this->assertSame([], $service->getAllergies(10));
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

        $service = new DoctorService($this->pdo);
        $this->assertSame($row, $service->getPrivacyConsents(10));
    }

    public function testGetPrivacyConsentsReturnsDefaultWhenNoRowExists(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->getPrivacyConsents(10);

        $this->assertSame('', $result['allergies_summary_text']);
        $this->assertSame('', $result['chronic_diagnostic_logs_text']);
        $this->assertSame('', $result['surgical_typologies_summary_text']);
        $this->assertSame(0, $result['surgical_typologies_necessary']);
        $this->assertNull($result['authored_by_doctor_name']);
        $this->assertNull($result['updated_at']);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function testAddMedicalRecordExecutesWithExpectedParams(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                'pid'      => 10,
                'vtype'    => 'Consultation',
                'hospital' => 'Kenyatta National Hospital',
                'vdate'    => '2026-07-01',
                'notes'    => 'Patient stable',
                'doc'      => 'Dr. Peliot B',
                'diag'     => 'Common cold',
                'treat'    => 'Rest and fluids',
            ])
            ->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->addMedicalRecord(
            10,
            'Consultation',
            'Kenyatta National Hospital',
            '2026-07-01',
            'Patient stable',
            'Dr. Peliot B',
            'Common cold',
            'Rest and fluids'
        );

        $this->assertTrue($result);
    }

    public function testAddPrescriptionExecutesWithExpectedParams(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                'pid'    => 10,
                'mname'  => 'Amoxicillin',
                'dosage' => '500mg',
                'freq'   => 'Twice daily',
                'dur'    => '7 days',
                'notes'  => 'Take with food',
                'doc'    => 'Dr. Peliot B',
            ])
            ->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->addPrescription(
            10,
            'Amoxicillin',
            '500mg',
            'Twice daily',
            '7 days',
            'Take with food',
            'Dr. Peliot B'
        );

        $this->assertTrue($result);
    }

    public function testSavePrivacyConsentExecutesWithExpectedParams(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                'patient_id' => 10,
                'allergies_summary_text' => 'Penicillin allergy.',
                'chronic_diagnostic_logs_text' => 'None.',
                'surgical_typologies_summary_text' => 'None.',
                'surgical_typologies_necessary' => 1,
                'doctor_id' => 3,
                'doctor_name' => 'Dr. Peliot B',
            ])
            ->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->savePrivacyConsent(
            10,
            'Penicillin allergy.',
            'None.',
            'None.',
            true,
            3,
            'Dr. Peliot B'
        );

        $this->assertTrue($result);
    }

    public function testSavePrivacyConsentCoercesBooleanFlagToInteger(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (array $params) {
                return $params['surgical_typologies_necessary'] === 0;
            }))
            ->willReturn(true);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $service->savePrivacyConsent(10, 'a', 'b', 'c', false, 3, 'Dr. Peliot B');
    }

    public function testAddMedicalRecordReturnsFalseWhenExecuteFails(): void
    {
        $stmt = $this->makeStatement(false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new DoctorService($this->pdo);
        $result = $service->addMedicalRecord(10, 'Consultation', 'Hospital', '2026-07-01', '', 'Dr. X', '', '');

        $this->assertFalse($result);
    }
}