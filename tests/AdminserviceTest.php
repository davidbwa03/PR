<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/AdminService.php';

/**
 * AdminServiceTest
 *
 * Unit tests for AdminService using a mocked PDO, so no real database
 * connection is required. Unlike DoctorService/InsuranceService, the
 * constructor here does nothing but store $pdo — ensureTables() is a
 * separate public method callers invoke explicitly, so tests don't
 * need to route around startup exec() calls.
 */
class AdminServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
    }

    private function makeStatement(bool $executeReturn = true, $fetchReturn = null, $fetchAllReturn = null, $fetchColumnReturn = null, ?int $rowCount = null): PDOStatement
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
        if ($rowCount !== null) {
            $stmt->method('rowCount')->willReturn($rowCount);
        }

        return $stmt;
    }

    // ------------------------------------------------------------------
    // Hospitals: read
    // ------------------------------------------------------------------

    public function testGetAllHospitalsReturnsRows(): void
    {
        $rows = [['id' => 1, 'name' => 'Kenyatta National Hospital']];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $this->pdo->method('query')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $this->assertSame($rows, $service->getAllHospitals());
    }

    public function testGetHospitalByIdReturnsNullForInvalidId(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new AdminService($this->pdo);

        $this->assertNull($service->getHospitalById(0));
    }

    public function testGetHospitalByIdReturnsRowWhenFound(): void
    {
        $hospital = ['id' => 3, 'name' => 'Aga Khan University Hospital'];
        $stmt = $this->makeStatement(true, $hospital);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $this->assertSame($hospital, $service->getHospitalById(3));
    }

    public function testGetHospitalByIdReturnsNullWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $this->assertNull($service->getHospitalById(999));
    }

    // ------------------------------------------------------------------
    // Practitioner id generation
    // ------------------------------------------------------------------

    public function testGenerateNextPractitionerIdStartsAtOneWhenNonePreviously(): void
    {
        $lookupStmt = $this->makeStatement(true, null, null, false);
        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->method('execute')->willReturn(true);
        $checkStmt->method('rowCount')->willReturn(0);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $checkStmt) {
            return str_contains($sql, 'ORDER BY practitioner_id DESC') ? $lookupStmt : $checkStmt;
        });

        $service = new AdminService($this->pdo);
        $year = date('Y');
        $this->assertSame("MD-{$year}-0001", $service->generateNextPractitionerId());
    }

    public function testGenerateNextPractitionerIdIncrementsFromLastSequence(): void
    {
        $year = date('Y');
        $lookupStmt = $this->makeStatement(true, null, null, "MD-{$year}-0007");
        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->method('execute')->willReturn(true);
        $checkStmt->method('rowCount')->willReturn(0);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $checkStmt) {
            return str_contains($sql, 'ORDER BY practitioner_id DESC') ? $lookupStmt : $checkStmt;
        });

        $service = new AdminService($this->pdo);
        $this->assertSame("MD-{$year}-0008", $service->generateNextPractitionerId());
    }

    public function testGenerateNextPractitionerIdSkipsCollisions(): void
    {
        $year = date('Y');
        $lookupStmt = $this->makeStatement(true, null, null, false);

        $checkStmt = $this->createMock(PDOStatement::class);
        $callCount = 0;
        $checkStmt->method('execute')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return true;
        });
        // First candidate (0001) collides, second (0002) is free.
        $checkStmt->method('rowCount')->willReturnCallback(function () use (&$callCount) {
            return $callCount === 1 ? 1 : 0;
        });

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $checkStmt) {
            return str_contains($sql, 'ORDER BY practitioner_id DESC') ? $lookupStmt : $checkStmt;
        });

        $service = new AdminService($this->pdo);
        $this->assertSame("MD-{$year}-0002", $service->generateNextPractitionerId());
    }

    // ------------------------------------------------------------------
    // addHospital()
    // ------------------------------------------------------------------

    private function baseHospitalData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jubilee Hospital',
            'email' => 'jubilee@example.com',
            'phone' => '0710103030',
            'county' => 'Nairobi',
            'address' => 'Upper Hill, Nairobi',
            'status' => 'Active',
            'login_password' => 'SecurePass123',
        ], $overrides);
    }

    public function testAddHospitalFailsWhenRequiredFieldsMissing(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new AdminService($this->pdo);

        $result = $service->addHospital($this->baseHospitalData(['name' => '']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Please fill in all required fields', $result['message']);
    }

    public function testAddHospitalFailsWhenActiveWithoutPassword(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new AdminService($this->pdo);

        $result = $service->addHospital($this->baseHospitalData(['login_password' => '']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Staff login password is required', $result['message']);
    }

    public function testAddHospitalFailsWhenPasswordTooShort(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new AdminService($this->pdo);

        $result = $service->addHospital($this->baseHospitalData(['login_password' => 'abc']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least 6 characters', $result['message']);
    }

    public function testAddHospitalFailsWhenDuplicateHospitalExists(): void
    {
        $stmt = $this->makeStatement(true, null, null, null, 1);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $result = $service->addHospital($this->baseHospitalData());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $result['message']);
    }

    public function testAddHospitalCreatesInactiveHospitalWithoutStaffAccount(): void
    {
        $duplicateCheckStmt = $this->makeStatement(true, null, null, null, 0);
        $insertHospitalStmt = $this->createMock(PDOStatement::class);
        $insertHospitalStmt->expects($this->once())->method('execute')->willReturn(true);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($duplicateCheckStmt, $insertHospitalStmt) {
            if (str_contains($sql, 'SELECT id FROM hospitals')) {
                return $duplicateCheckStmt;
            }
            return $insertHospitalStmt;
        });
        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->once())->method('commit');

        $service = new AdminService($this->pdo);
        $result = $service->addHospital($this->baseHospitalData(['status' => 'Inactive', 'login_password' => '']));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('No staff account was created', $result['message']);
        $this->assertArrayNotHasKey('practitioner_id', $result);
    }

    public function testAddHospitalCreatesActiveHospitalWithStaffAccount(): void
    {
        $duplicateHospitalStmt = $this->makeStatement(true, null, null, null, 0);
        $duplicateStaffStmt = $this->makeStatement(true, null, null, null, 0);
        $insertHospitalStmt = $this->createMock(PDOStatement::class);
        $insertHospitalStmt->method('execute')->willReturn(true);
        $insertStaffStmt = $this->createMock(PDOStatement::class);
        $insertStaffStmt->expects($this->once())->method('execute')->willReturn(true);

        $lookupPractitionerStmt = $this->makeStatement(true, null, null, false);
        $checkPractitionerStmt = $this->createMock(PDOStatement::class);
        $checkPractitionerStmt->method('execute')->willReturn(true);
        $checkPractitionerStmt->method('rowCount')->willReturn(0);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use (
            $duplicateHospitalStmt, $duplicateStaffStmt, $insertHospitalStmt, $insertStaffStmt,
            $lookupPractitionerStmt, $checkPractitionerStmt
        ) {
            if (str_contains($sql, 'SELECT id FROM hospitals')) {
                return $duplicateHospitalStmt;
            }
            if (str_contains($sql, 'ORDER BY practitioner_id DESC')) {
                return $lookupPractitionerStmt;
            }
            if (str_contains($sql, 'SELECT id FROM staff WHERE practitioner_id')) {
                return $checkPractitionerStmt;
            }
            if (str_contains($sql, 'SELECT id FROM staff WHERE email')) {
                return $duplicateStaffStmt;
            }
            if (str_contains($sql, 'INSERT INTO hospitals')) {
                return $insertHospitalStmt;
            }
            return $insertStaffStmt;
        });

        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->once())->method('commit');

        $service = new AdminService($this->pdo);
        $result = $service->addHospital($this->baseHospitalData());

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('practitioner_id', $result);
        $this->assertStringStartsWith('MD-' . date('Y') . '-', $result['practitioner_id']);
    }

    public function testAddHospitalRollsBackOnDuplicateStaffEmail(): void
    {
        $duplicateHospitalStmt = $this->makeStatement(true, null, null, null, 0);
        $duplicateStaffStmt = $this->makeStatement(true, null, null, null, 1);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($duplicateHospitalStmt, $duplicateStaffStmt) {
            if (str_contains($sql, 'SELECT id FROM hospitals')) {
                return $duplicateHospitalStmt;
            }
            return $duplicateStaffStmt;
        });

        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('rollBack');

        $service = new AdminService($this->pdo);
        $result = $service->addHospital($this->baseHospitalData());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('staff account with this email already exists', $result['message']);
    }

    // ------------------------------------------------------------------
    // updateHospital()
    // ------------------------------------------------------------------

    public function testUpdateHospitalFailsWhenRequiredFieldsMissing(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new AdminService($this->pdo);

        $result = $service->updateHospital(['id' => 0, 'name' => '']);

        $this->assertFalse($result['success']);
    }

    public function testUpdateHospitalFailsWhenHospitalNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);
        $this->pdo->method('inTransaction')->willReturn(false);

        $service = new AdminService($this->pdo);
        $result = $service->updateHospital([
            'id' => 5, 'name' => 'X', 'email' => 'x@example.com', 'phone' => '123', 'county' => 'Nairobi', 'address' => 'Addr',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Hospital not found', $result['message']);
    }

    public function testUpdateHospitalSucceedsAndSyncsStaff(): void
    {
        $existingStmt = $this->makeStatement(true, ['name' => 'Old Name', 'email' => 'old@example.com']);
        $updateHospitalStmt = $this->createMock(PDOStatement::class);
        $updateHospitalStmt->method('execute')->willReturn(true);
        $syncStaffStmt = $this->createMock(PDOStatement::class);
        $syncStaffStmt->method('execute')->willReturn(true);
        $syncStaffStmt->method('rowCount')->willReturn(1);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($existingStmt, $updateHospitalStmt, $syncStaffStmt) {
            if (str_contains($sql, 'SELECT name, email FROM hospitals')) {
                return $existingStmt;
            }
            if (str_contains($sql, 'UPDATE hospitals')) {
                return $updateHospitalStmt;
            }
            return $syncStaffStmt;
        });

        $service = new AdminService($this->pdo);
        $result = $service->updateHospital([
            'id' => 5, 'name' => 'New Name', 'email' => 'new@example.com', 'phone' => '123', 'county' => 'Nairobi',
            'address' => 'Addr', 'status' => 'Active',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('staff account synced', $result['message']);
    }

    // ------------------------------------------------------------------
    // deleteHospital()
    // ------------------------------------------------------------------

    public function testDeleteHospitalRejectsInvalidId(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $service = new AdminService($this->pdo);

        $result = $service->deleteHospital(0);

        $this->assertFalse($result['success']);
    }

    public function testDeleteHospitalFailsWhenNotFound(): void
    {
        $stmt = $this->makeStatement(true, false);
        $this->pdo->method('prepare')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $result = $service->deleteHospital(999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    public function testDeleteHospitalSucceedsAndReportsLinkedStaffCount(): void
    {
        $lookupStmt = $this->makeStatement(true, ['id' => 3, 'name' => 'Jubilee', 'email' => 'jubilee@example.com']);
        $deleteStaffStmt = $this->createMock(PDOStatement::class);
        $deleteStaffStmt->method('execute')->willReturn(true);
        $deleteStaffStmt->method('rowCount')->willReturn(2);
        $deleteHospitalStmt = $this->createMock(PDOStatement::class);
        $deleteHospitalStmt->method('execute')->willReturn(true);
        $deleteHospitalStmt->method('rowCount')->willReturn(1);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) use ($lookupStmt, $deleteStaffStmt, $deleteHospitalStmt) {
            if (str_contains($sql, 'SELECT id, name, email FROM hospitals')) {
                return $lookupStmt;
            }
            if (str_contains($sql, 'DELETE FROM staff')) {
                return $deleteStaffStmt;
            }
            return $deleteHospitalStmt;
        });

        $service = new AdminService($this->pdo);
        $result = $service->deleteHospital(3);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Linked staff deleted: 2', $result['message']);
    }

    // ------------------------------------------------------------------
    // Directories
    // ------------------------------------------------------------------

    public function testGetAllDoctorsReturnsRows(): void
    {
        $rows = [['id' => 1, 'name' => 'Dr. Peliot B', 'status' => 'Active']];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $this->pdo->method('query')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $this->assertSame($rows, $service->getAllDoctors());
    }

    public function testGetAllPatientsReturnsRows(): void
    {
        $rows = [['id' => 1, 'name' => 'David Bwashi']];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $this->pdo->method('query')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $this->assertSame($rows, $service->getAllPatients());
    }

    public function testGetAllPatientsReturnsEmptyArrayOnException(): void
    {
        $this->pdo->method('query')->willThrowException(new PDOException('Connection lost'));

        $service = new AdminService($this->pdo);
        $this->assertSame([], $service->getAllPatients());
    }

    // ------------------------------------------------------------------
    // Access requests
    // ------------------------------------------------------------------

    public function testDetectAccessRequestsSchemaPrefersStatusOverRequestStatus(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn(['id', 'status', 'request_status', 'created_at']);
        $this->pdo->method('query')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $schema = $service->detectAccessRequestsSchema();

        $this->assertSame('status', $schema['status_column']);
        $this->assertSame('created_at DESC', $schema['order_by']);
    }

    public function testDetectAccessRequestsSchemaFallsBackToRequestStatusAndRequestedAt(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn(['id', 'request_status', 'requested_at']);
        $this->pdo->method('query')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $schema = $service->detectAccessRequestsSchema();

        $this->assertSame('request_status', $schema['status_column']);
        $this->assertSame('requested_at DESC', $schema['order_by']);
    }

    public function testDetectAccessRequestsSchemaFallsBackWhenQueryFails(): void
    {
        $this->pdo->method('query')->willThrowException(new PDOException('no such table'));

        $service = new AdminService($this->pdo);
        $schema = $service->detectAccessRequestsSchema();

        $this->assertNull($schema['status_column']);
        $this->assertSame('id DESC', $schema['order_by']);
    }

    public function testUpdateAccessRequestStatusRejectsInvalidAction(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn(['id', 'status']);
        $this->pdo->method('query')->willReturn($stmt);
        $this->pdo->expects($this->never())->method('prepare');

        $service = new AdminService($this->pdo);
        $result = $service->updateAccessRequestStatus(1, 'delete');

        $this->assertFalse($result['success']);
    }

    public function testUpdateAccessRequestStatusFailsWhenStatusColumnUndetected(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn(['id']);
        $this->pdo->method('query')->willReturn($stmt);
        $this->pdo->expects($this->never())->method('prepare');

        $service = new AdminService($this->pdo);
        $result = $service->updateAccessRequestStatus(1, 'approve');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not determine', $result['message']);
    }

    public function testUpdateAccessRequestStatusApprovesSuccessfully(): void
    {
        $schemaStmt = $this->createMock(PDOStatement::class);
        $schemaStmt->method('fetchAll')->willReturn(['id', 'status']);
        $this->pdo->method('query')->willReturn($schemaStmt);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->once())->method('execute')->with(['approved', 7])->willReturn(true);
        $this->pdo->method('prepare')->willReturn($updateStmt);

        $service = new AdminService($this->pdo);
        $result = $service->updateAccessRequestStatus(7, 'approve');

        $this->assertTrue($result['success']);
    }

    public function testGetDoctorStatusByNameBuildsLowercaseMap(): void
    {
        $columnsStmt = $this->createMock(PDOStatement::class);
        $columnsStmt->method('fetchAll')->willReturn(['name', 'status']);
        $rowsStmt = $this->createMock(PDOStatement::class);
        $rowsStmt->method('fetchAll')->willReturn([
            ['name' => 'Dr. Peliot B', 'status' => 'Active'],
            ['name' => 'Dr. Jane Doe', 'status' => 'Inactive'],
        ]);

        $this->pdo->method('query')->willReturnCallback(function (string $sql) use ($columnsStmt, $rowsStmt) {
            return str_contains($sql, 'SHOW COLUMNS') ? $columnsStmt : $rowsStmt;
        });

        $service = new AdminService($this->pdo);
        $map = $service->getDoctorStatusByName();

        $this->assertSame('active', $map['dr. peliot b']);
        $this->assertSame('inactive', $map['dr. jane doe']);
    }

    // ------------------------------------------------------------------
    // Dashboard / reports
    // ------------------------------------------------------------------

    public function testSafeCountReturnsZeroOnException(): void
    {
        $this->pdo->method('query')->willThrowException(new PDOException('bad table'));
        $service = new AdminService($this->pdo);

        $this->assertSame(0, $service->safeCount('SELECT COUNT(*) FROM nonexistent'));
    }

    public function testGetDashboardStatsReturnsAllFourCounts(): void
    {
        $callIndex = 0;
        $values = [3, 12, 40, 5];
        $this->pdo->method('query')->willReturnCallback(function () use (&$callIndex, $values) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn($values[$callIndex++]);
            return $stmt;
        });

        $service = new AdminService($this->pdo);
        $stats = $service->getDashboardStats();

        $this->assertSame(3, $stats['total_hospitals']);
        $this->assertSame(12, $stats['total_doctors']);
        $this->assertSame(40, $stats['total_patients']);
        $this->assertSame(5, $stats['pending_requests']);
    }

    public function testGetRecentDoctorsReturnsRows(): void
    {
        $rows = [['name' => 'Dr. Peliot B', 'specialty' => 'Dentist', 'status' => 'Active']];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $this->pdo->method('query')->willReturn($stmt);

        $service = new AdminService($this->pdo);
        $this->assertSame($rows, $service->getRecentDoctors(5));
    }
}