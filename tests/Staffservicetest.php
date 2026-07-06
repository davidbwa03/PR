<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../staff/StaffService.php';

/**
 * StaffServiceTest
 *
 * Unit tests for StaffService using a mocked PDO, so no real database
 * connection is required.
 *
 * Mocking strategy: the constructor always issues one query() call —
 * "SHOW COLUMNS FROM doctors LIKE 'status'" — to detect whether this
 * install's doctors table has a status column. Every test's PDO mock
 * must answer that first before any other query()/prepare() call.
 */
class StaffServiceTest extends TestCase
{
    /**
     * Build a PDO mock whose query() responds based on the SQL passed in.
     *
     * @param array<string,mixed> $responses Map of SQL-substring => rows
     *   (array) returned via fetchAll(), or scalar returned via fetchColumn().
     * @param bool $hasStatusColumn Whether "SHOW COLUMNS ... LIKE 'status'"
     *   should report the column as present.
     */
    private function makePdoWithQueryRouting(array $responses, bool $hasStatusColumn = true): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('query')->willReturnCallback(function (string $sql) use ($responses, $hasStatusColumn) {
            if (str_contains($sql, "SHOW COLUMNS FROM doctors LIKE 'status'")) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetch')->willReturn($hasStatusColumn ? ['Field' => 'status'] : false);
                return $stmt;
            }

            foreach ($responses as $needle => $rowsOrValue) {
                if (str_contains($sql, $needle)) {
                    $stmt = $this->createMock(PDOStatement::class);
                    if (is_array($rowsOrValue)) {
                        $stmt->method('fetchAll')->willReturn($rowsOrValue);
                    } else {
                        $stmt->method('fetchColumn')->willReturn($rowsOrValue);
                    }
                    return $stmt;
                }
            }

            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([]);
            $stmt->method('fetchColumn')->willReturn(0);
            return $stmt;
        });

        return $pdo;
    }

    private function makeStatement(bool $executeReturn = true, $fetchReturn = null, $fetchColumnReturn = null): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn($executeReturn);

        if ($fetchReturn !== null) {
            $stmt->method('fetch')->willReturn($fetchReturn);
        }
        if ($fetchColumnReturn !== null) {
            $stmt->method('fetchColumn')->willReturn($fetchColumnReturn);
        }

        return $stmt;
    }

    // ------------------------------------------------------------------
    // Schema detection
    // ------------------------------------------------------------------

    public function testDetectsStatusColumnWhenPresent(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $service = new StaffService($pdo);

        $this->assertTrue($service->doctorsHaveStatusColumn());
    }

    public function testDetectsMissingStatusColumn(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], false);
        $service = new StaffService($pdo);

        $this->assertFalse($service->doctorsHaveStatusColumn());
    }

    public function testFallsBackGracefullyWhenSchemaCheckThrows(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willThrowException(new PDOException('Table doctors does not exist'));

        $service = new StaffService($pdo);

        $this->assertFalse($service->doctorsHaveStatusColumn());
    }

    // ------------------------------------------------------------------
    // Hospital branding
    // ------------------------------------------------------------------

    public function testGetHospitalNameReturnsResolvedValue(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->makeStatement(true, null, 'Kenyatta National Hospital');
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $this->assertSame('Kenyatta National Hospital', $service->getHospitalName(2));
    }

    public function testGetHospitalNameFallsBackWhenStaffIdIsZero(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $this->assertSame('Hospital', $service->getHospitalName(0));
    }

    public function testGetHospitalNameFallsBackOnPdoException(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->method('prepare')->willThrowException(new PDOException('Connection lost'));

        $service = new StaffService($pdo);
        $this->assertSame('Hospital', $service->getHospitalName(2, 'Hospital'));
    }

    // ------------------------------------------------------------------
    // Practitioner management
    // ------------------------------------------------------------------

    public function testAddPractitionerSucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->addPractitioner([
            'name' => 'Dr. Peliot B',
            'specialty' => 'Dentist',
            'email' => 'peliot@example.com',
            'password' => 'SecurePass123',
            'phone' => '0710103030',
            'dob' => '1998-03-20',
            'gender' => 'Male',
            'address' => 'Nairobi, Kenya',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Doctor added successfully.', $result['message']);
    }

    public function testAddPractitionerFailsOnDuplicateEmail(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willThrowException(new PDOException('Duplicate entry'));
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->addPractitioner(['name' => 'Dr. X', 'email' => 'dup@example.com', 'password' => 'pass1234']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('email might already be registered', $result['message']);
    }

    public function testDeleteDoctorRejectsInvalidId(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $this->assertFalse($service->deleteDoctor(0));
    }

    public function testDeleteDoctorSucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([5])->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $this->assertTrue($service->deleteDoctor(5));
    }

    public function testGetDoctorsUsesStatusColumnQueryWhenAvailable(): void
    {
        $rows = [['id' => 1, 'name' => 'Dr. Peliot B', 'specialty' => 'Dentist', 'status' => 'Active']];
        $pdo = $this->makePdoWithQueryRouting(['SELECT id, name, specialty, status FROM doctors' => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getDoctors());
    }

    public function testGetDoctorsFallsBackToInactiveWhenStatusColumnMissing(): void
    {
        $rows = [['id' => 1, 'name' => 'Dr. Peliot B', 'specialty' => 'Dentist', 'status' => 'inactive']];
        $pdo = $this->makePdoWithQueryRouting(["'inactive' AS status FROM doctors" => $rows], false);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getDoctors());
    }

    public function testUpdateDoctorStatusFailsWhenColumnMissing(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], false);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->updateDoctorStatus(1, 'active');

        $this->assertFalse($result['success']);
        $this->assertSame('Status column is not available in the doctors table.', $result['message']);
    }

    public function testUpdateDoctorStatusSucceedsAndNormalizesInvalidValue(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['status' => 'inactive', 'id' => 3])->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->updateDoctorStatus(3, 'something-weird');

        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // Patient reference resolution
    // ------------------------------------------------------------------

    public function testResolvePatientReferenceHandlesFormattedReference(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([1]);
        $stmt->method('fetch')->willReturn(['id' => 1, 'name' => 'David Bwashi']);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->resolvePatientReference('PT-2026-1');

        $this->assertSame(['id' => 1, 'name' => 'David Bwashi'], $result);
    }

    public function testResolvePatientReferenceHandlesRawNumericId(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([7]);
        $stmt->method('fetch')->willReturn(['id' => 7, 'name' => 'Jane Doe']);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->resolvePatientReference('7');

        $this->assertSame(['id' => 7, 'name' => 'Jane Doe'], $result);
    }

    public function testResolvePatientReferenceFallsBackToNationalIdOrEmail(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['islaehrk@gmail.com', 'islaehrk@gmail.com']);
        $stmt->method('fetch')->willReturn(['id' => 1, 'name' => 'David Bwashi']);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->resolvePatientReference('islaehrk@gmail.com');

        $this->assertSame(['id' => 1, 'name' => 'David Bwashi'], $result);
    }

    public function testResolvePatientReferenceReturnsNullForEmptyInput(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $this->assertNull($service->resolvePatientReference('   '));
    }

    // ------------------------------------------------------------------
    // Access requests
    // ------------------------------------------------------------------

    public function testGetAllPatientRequestsReturnsRows(): void
    {
        $rows = [['id' => 1, 'patient_id' => 1, 'request_status' => 'approved']];
        $pdo = $this->makePdoWithQueryRouting(['FROM access_requests ar' => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getAllPatientRequests());
    }

    public function testRequestPatientSummaryFailsWhenReferenceEmpty(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->requestPatientSummary('', 'Admin', 'Hospital');

        $this->assertFalse($result['success']);
    }

    public function testRequestPatientSummaryFailsWhenPatientNotFound(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->requestPatientSummary('nonexistent@example.com', 'Admin', 'Hospital');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No patient matched', $result['message']);
    }

    public function testRequestPatientSummarySucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);

        $lookupStmt = $this->createMock(PDOStatement::class);
        $lookupStmt->method('execute')->willReturn(true);
        $lookupStmt->method('fetch')->willReturn(['id' => 1, 'name' => 'David Bwashi']);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())
            ->method('execute')
            ->with([1, 'Admin', 'Kenyatta National Hospital'])
            ->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $insertStmt) {
            return str_contains($sql, 'INSERT INTO access_requests') ? $insertStmt : $lookupStmt;
        });

        $service = new StaffService($pdo);
        $result = $service->requestPatientSummary('1', 'Admin', 'Kenyatta National Hospital');

        $this->assertTrue($result['success']);
    }

    public function testRequestPatientDataRequiresAllFields(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->requestPatientData(0, '', '');

        $this->assertFalse($result['success']);
    }

    public function testRequestPatientDataSucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([3, 'Dr. Peliot B', 'Kenyatta National Hospital'])
            ->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->requestPatientData(3, 'Dr. Peliot B', 'Kenyatta National Hospital');

        $this->assertTrue($result['success']);
    }

    public function testSendRecordsToDoctorRejectsInvalidId(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->sendRecordsToDoctor(0);

        $this->assertFalse($result['success']);
    }

    public function testSendRecordsToDoctorSucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([9])->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->sendRecordsToDoctor(9);

        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // Vitals
    // ------------------------------------------------------------------

    private function baseVitalsData(array $overrides = []): array
    {
        return array_merge([
            'patient_ref' => 'PT-2026-1',
            'blood_pressure' => '120/80',
            'heart_rate' => '72',
            'temperature' => '36.6',
            'vitals_date' => '2026-07-01',
            'notes' => 'Routine check',
        ], $overrides);
    }

    public function testUpdateVitalsFailsWhenRequiredFieldsMissing(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->updateVitals($this->baseVitalsData(['blood_pressure' => '']), 'Staff', 'Hospital');

        $this->assertFalse($result['success']);
    }

    public function testUpdateVitalsFailsOnInvalidDate(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->updateVitals($this->baseVitalsData(['vitals_date' => '07/01/2026']), 'Staff', 'Hospital');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid vitals date', $result['message']);
    }

    public function testUpdateVitalsFailsWhenPatientNotFound(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->updateVitals($this->baseVitalsData(), 'Staff', 'Hospital');

        $this->assertFalse($result['success']);
        $this->assertSame('No patient matched the provided reference.', $result['message']);
    }

    public function testUpdateVitalsSucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);

        $lookupStmt = $this->createMock(PDOStatement::class);
        $lookupStmt->method('execute')->willReturn(true);
        $lookupStmt->method('fetch')->willReturn(['id' => 1, 'name' => 'David Bwashi']);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $insertStmt) {
            return str_contains($sql, 'INSERT INTO medical_records') ? $insertStmt : $lookupStmt;
        });

        $service = new StaffService($pdo);
        $result = $service->updateVitals($this->baseVitalsData(), 'Staff Name', 'Kenyatta National Hospital');

        $this->assertTrue($result['success']);
        $this->assertSame('Vitals were saved for David Bwashi.', $result['message']);
    }

    public function testUpdateVitalsReturnsFailureMessageOnPdoException(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);

        $lookupStmt = $this->createMock(PDOStatement::class);
        $lookupStmt->method('execute')->willReturn(true);
        $lookupStmt->method('fetch')->willReturn(['id' => 1, 'name' => 'David Bwashi']);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willThrowException(new PDOException('Deadlock'));

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $insertStmt) {
            return str_contains($sql, 'INSERT INTO medical_records') ? $insertStmt : $lookupStmt;
        });

        $service = new StaffService($pdo);
        $result = $service->updateVitals($this->baseVitalsData(), 'Staff Name', 'Hospital');

        $this->assertFalse($result['success']);
        $this->assertSame('Could not save vitals right now. Please try again.', $result['message']);
    }

    // ------------------------------------------------------------------
    // Password reset
    // ------------------------------------------------------------------

    public function testResetStaffPasswordFailsWhenPasswordsDoNotMatch(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->resetStaffPassword(1, 'SecurePass123', 'Different123');

        $this->assertFalse($result['success']);
        $this->assertSame('Form credentials do not match.', $result['message']);
    }

    public function testResetStaffPasswordFailsWhenTooShort(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $pdo->expects($this->never())->method('prepare');

        $service = new StaffService($pdo);
        $result = $service->resetStaffPassword(1, 'short', 'short');

        $this->assertFalse($result['success']);
        $this->assertSame('Password must comprise at least 8 elements.', $result['message']);
    }

    public function testResetStaffPasswordSucceeds(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], true);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new StaffService($pdo);
        $result = $service->resetStaffPassword(1, 'SecurePass123', 'SecurePass123');

        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // Dashboard metrics
    // ------------------------------------------------------------------

    public function testGetActivePractitionersCountReturnsValue(): void
    {
        $pdo = $this->makePdoWithQueryRouting(['SELECT (SELECT COUNT(*) FROM staff)' => 5], true);
        $service = new StaffService($pdo);
        $this->assertSame(5, $service->getActivePractitionersCount());
    }

    public function testGetActivePractitionersCountReturnsZeroOnException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, "SHOW COLUMNS")) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetch')->willReturn(['Field' => 'status']);
                return $stmt;
            }
            throw new PDOException('Connection lost');
        });

        $service = new StaffService($pdo);
        $this->assertSame(0, $service->getActivePractitionersCount());
    }

    public function testGetPatientsTodayCountFallsBackToTotalWhenZeroToday(): void
    {
        $pdo = $this->createMock(PDO::class);
        $callCount = 0;
        $pdo->method('query')->willReturnCallback(function (string $sql) use (&$callCount) {
            if (str_contains($sql, "SHOW COLUMNS")) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetch')->willReturn(['Field' => 'status']);
                return $stmt;
            }
            $callCount++;
            $stmt = $this->createMock(PDOStatement::class);
            // First call: today's count (0). Second call: total count (42).
            $stmt->method('fetchColumn')->willReturn($callCount === 1 ? 0 : 42);
            return $stmt;
        });

        $service = new StaffService($pdo);
        $this->assertSame(42, $service->getPatientsTodayCount());
    }

    public function testGetDataRequestsCountReturnsValue(): void
    {
        $pdo = $this->makePdoWithQueryRouting(['SELECT COUNT(*) FROM access_requests' => 12], true);
        $service = new StaffService($pdo);
        $this->assertSame(12, $service->getDataRequestsCount());
    }

    public function testGetPractitionersListReturnsRows(): void
    {
        $rows = [['id' => 1, 'name' => 'Dr. Peliot B', 'specialty' => 'Dentist', 'type' => 'doctors']];
        $pdo = $this->makePdoWithQueryRouting(['UNION ALL' => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getPractitionersList());
    }

    public function testGetRecentRequestsReturnsRows(): void
    {
        $rows = [['id' => 1, 'patient_id' => 1, 'doctor_name' => 'Dr. Peliot B']];
        $pdo = $this->makePdoWithQueryRouting(['FROM access_requests ORDER BY requested_at DESC LIMIT' => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getRecentRequests());
    }

    // ------------------------------------------------------------------
    // Analytics
    // ------------------------------------------------------------------

    public function testGetPatientTrendsReturnsRows(): void
    {
        $rows = [['month' => 'June', 'total' => 10]];
        $pdo = $this->makePdoWithQueryRouting(['GROUP BY MONTH(created_at)' => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getPatientTrends());
    }

    public function testGetRequestStatusDistributionReturnsRows(): void
    {
        $rows = [['request_status' => 'approved', 'count' => 3]];
        $pdo = $this->makePdoWithQueryRouting(['GROUP BY request_status' => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getRequestStatusDistribution());
    }

    public function testGetRoleDistributionReturnsRows(): void
    {
        $rows = [
            ['role' => 'Staff', 'count' => 2],
            ['role' => 'Doctors', 'count' => 4],
            ['role' => 'Patients', 'count' => 1],
        ];
        $pdo = $this->makePdoWithQueryRouting(["SELECT 'Staff' as role" => $rows], true);

        $service = new StaffService($pdo);
        $this->assertSame($rows, $service->getRoleDistribution());
    }

    public function testGetRoleDistributionReturnsEmptyArrayOnException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, "SHOW COLUMNS")) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetch')->willReturn(['Field' => 'status']);
                return $stmt;
            }
            throw new PDOException('Connection lost');
        });

        $service = new StaffService($pdo);
        $this->assertSame([], $service->getRoleDistribution());
    }
}