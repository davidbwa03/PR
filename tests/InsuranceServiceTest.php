<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../insurance/InsuranceService.php';

/**
 * InsuranceServiceTest
 *
 * Unit tests for InsuranceService using a mocked PDO, so no real
 * database connection is required.
 *
 * Mocking strategy:
 *   - The constructor always issues one query(): "SHOW COLUMNS FROM claims".
 *     Every test's PDO mock must answer that call (or throw, to test the
 *     fallback path) before any other query()/prepare() calls happen.
 *   - Because query() is called both during schema detection and during
 *     getPendingClaims()/getReviewedClaims(), tests that need both stub
 *     query() with a callback keyed off the SQL text rather than a single
 *     canned return value.
 */
class InsuranceServiceTest extends TestCase
{
    /**
     * Build a PDO mock whose query() responds based on the SQL passed in.
     *
     * @param array<string,mixed> $responses Map of SQL-substring => rows
     *   (or a Closure returning a PDOStatement-like mock) used to decide
     *   what each query() call returns.
     * @param string[] $schemaColumns Column names reported for
     *   "SHOW COLUMNS FROM claims"; null to make that call throw.
     */
    private function makePdoWithQueryRouting(array $responses, ?array $schemaColumns = []): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('query')->willReturnCallback(function (string $sql) use ($responses, $schemaColumns) {
            if (str_contains($sql, 'SHOW COLUMNS FROM claims')) {
                if ($schemaColumns === null) {
                    throw new PDOException('Table claims does not exist');
                }
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetchAll')->willReturn($schemaColumns);
                return $stmt;
            }

            foreach ($responses as $needle => $rows) {
                if (str_contains($sql, $needle)) {
                    $stmt = $this->createMock(PDOStatement::class);
                    $stmt->method('fetchAll')->willReturn($rows);
                    return $stmt;
                }
            }

            // Unrecognized query — return an empty result rather than
            // failing the whole test on an unexpected SQL shape.
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        return $pdo;
    }

    // ------------------------------------------------------------------
    // Schema detection
    // ------------------------------------------------------------------

    public function testDefaultsToStandardColumnNamesWhenNeitherAltColumnExists(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], ['claim_id', 'status', 'reviewed_date', 'rejection_reason']);

        $service = new InsuranceService($pdo);

        $this->assertSame('reviewed_date', $service->getReviewedDateColumn());
        $this->assertSame('rejection_reason', $service->getRejectionReasonColumn());
    }

    public function testDetectsAlternateColumnNamesWhenPresent(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], ['claim_id', 'status', 'reviewed_at', 'decline_reason']);

        $service = new InsuranceService($pdo);

        $this->assertSame('reviewed_at', $service->getReviewedDateColumn());
        $this->assertSame('decline_reason', $service->getRejectionReasonColumn());
    }

    public function testFallsBackToDefaultsWhenShowColumnsFails(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], null);

        $service = new InsuranceService($pdo);

        $this->assertSame('reviewed_date', $service->getReviewedDateColumn());
        $this->assertSame('rejection_reason', $service->getRejectionReasonColumn());
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function testGetPendingClaimsReturnsRows(): void
    {
        $rows = [
            ['claim_id' => 1, 'claim_number' => 'CLM-001', 'status' => 'pending'],
        ];
        $pdo = $this->makePdoWithQueryRouting(
            ["WHERE LOWER(TRIM(c.status)) = 'pending'" => $rows],
            ['claim_id', 'status']
        );

        $service = new InsuranceService($pdo);
        $result = $service->getPendingClaims();

        $this->assertSame($rows, $result);
    }

    public function testGetPendingClaimsReturnsEmptyArrayOnPdoException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'SHOW COLUMNS FROM claims')) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetchAll')->willReturn([]);
                return $stmt;
            }
            throw new PDOException('Connection lost');
        });

        $service = new InsuranceService($pdo);
        $result = $service->getPendingClaims();

        $this->assertSame([], $result);
    }

    public function testGetReviewedClaimsReturnsRows(): void
    {
        $rows = [
            ['claim_id' => 2, 'claim_number' => 'CLM-002', 'status' => 'approved'],
        ];
        $pdo = $this->makePdoWithQueryRouting(
            ["IN ('approved', 'rejected', 'declined')" => $rows],
            ['claim_id', 'status', 'reviewed_at', 'decline_reason']
        );

        $service = new InsuranceService($pdo);
        $result = $service->getReviewedClaims(8);

        $this->assertSame($rows, $result);
    }

    public function testGetReviewedClaimsReturnsEmptyArrayOnPdoException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'SHOW COLUMNS FROM claims')) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('fetchAll')->willReturn([]);
                return $stmt;
            }
            throw new PDOException('Connection lost');
        });

        $service = new InsuranceService($pdo);
        $result = $service->getReviewedClaims();

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // reviewClaim() — validation paths (no DB access expected)
    // ------------------------------------------------------------------

    public function testReviewClaimRejectsInvalidClaimId(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], []);
        $service = new InsuranceService($pdo);

        $result = $service->reviewClaim(0, 'approve', 5);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid claim action.', $result['message']);
    }

    public function testReviewClaimRejectsUnknownAction(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], []);
        $service = new InsuranceService($pdo);

        $result = $service->reviewClaim(1, 'delete', 5);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid claim action.', $result['message']);
    }

    public function testReviewClaimRequiresDeclineReason(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], []);
        $service = new InsuranceService($pdo);

        $result = $service->reviewClaim(1, 'decline', 5, '   ');

        $this->assertFalse($result['success']);
        $this->assertSame('Please provide a reason for declining this claim.', $result['message']);
    }

    // ------------------------------------------------------------------
    // reviewClaim() — DB-backed paths
    // ------------------------------------------------------------------

    public function testReviewClaimFailsWhenClaimNotFound(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], []);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new InsuranceService($pdo);
        $result = $service->reviewClaim(999, 'approve', 5);

        $this->assertFalse($result['success']);
        $this->assertSame('Claim not found.', $result['message']);
    }

    public function testReviewClaimFailsWhenAlreadyReviewed(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], []);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['claim_id' => 1, 'status' => 'Approved', 'claim_number' => 'CLM-001']);
        $pdo->method('prepare')->willReturn($stmt);

        $service = new InsuranceService($pdo);
        $result = $service->reviewClaim(1, 'decline', 5, 'Not covered');

        $this->assertFalse($result['success']);
        $this->assertSame('This claim has already been reviewed.', $result['message']);
    }

    public function testReviewClaimApprovesSuccessfully(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], ['claim_id', 'status', 'reviewed_date', 'rejection_reason']);

        $summaryStmt = $this->createMock(PDOStatement::class);
        $summaryStmt->method('execute')->willReturn(true);
        $summaryStmt->method('fetch')->willReturn(['claim_id' => 1, 'status' => 'pending', 'claim_number' => 'CLM-001']);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->once())
            ->method('execute')
            ->with(['Approved', 5, 1])
            ->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($summaryStmt, $updateStmt) {
            return str_contains($sql, 'SELECT claim_id, status, claim_number') ? $summaryStmt : $updateStmt;
        });

        $service = new InsuranceService($pdo);
        $result = $service->reviewClaim(1, 'approve', 5);

        $this->assertTrue($result['success']);
        $this->assertSame('Claim CLM-001 has been approved.', $result['message']);
    }

    public function testReviewClaimDeclinesSuccessfullyAndIncludesReason(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], ['claim_id', 'status', 'reviewed_date', 'rejection_reason']);

        $summaryStmt = $this->createMock(PDOStatement::class);
        $summaryStmt->method('execute')->willReturn(true);
        $summaryStmt->method('fetch')->willReturn(['claim_id' => 2, 'status' => 'pending', 'claim_number' => 'CLM-002']);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->once())
            ->method('execute')
            ->with(['Rejected', 'Pre-existing condition not covered', 5, 2])
            ->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($summaryStmt, $updateStmt) {
            return str_contains($sql, 'SELECT claim_id, status, claim_number') ? $summaryStmt : $updateStmt;
        });

        $service = new InsuranceService($pdo);
        $result = $service->reviewClaim(2, 'decline', 5, 'Pre-existing condition not covered');

        $this->assertTrue($result['success']);
        $this->assertSame('Claim CLM-002 has been rejected.', $result['message']);
    }

    public function testReviewClaimUsesDetectedColumnNamesInUpdate(): void
    {
        // Alternate schema: reviewed_at / decline_reason instead of the defaults.
        $pdo = $this->makePdoWithQueryRouting([], ['claim_id', 'status', 'reviewed_at', 'decline_reason']);

        $summaryStmt = $this->createMock(PDOStatement::class);
        $summaryStmt->method('execute')->willReturn(true);
        $summaryStmt->method('fetch')->willReturn(['claim_id' => 3, 'status' => 'pending', 'claim_number' => 'CLM-003']);

        $capturedSql = null;
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($summaryStmt, $updateStmt, &$capturedSql) {
            if (str_contains($sql, 'SELECT claim_id, status, claim_number')) {
                return $summaryStmt;
            }
            $capturedSql = $sql;
            return $updateStmt;
        });

        $service = new InsuranceService($pdo);
        $service->reviewClaim(3, 'decline', 5, 'Not eligible');

        $this->assertStringContainsString('`decline_reason`', $capturedSql);
        $this->assertStringContainsString('`reviewed_at`', $capturedSql);
    }

    public function testReviewClaimReturnsFailureMessageOnPdoException(): void
    {
        $pdo = $this->makePdoWithQueryRouting([], []);

        $summaryStmt = $this->createMock(PDOStatement::class);
        $summaryStmt->method('execute')->willReturn(true);
        $summaryStmt->method('fetch')->willReturn(['claim_id' => 1, 'status' => 'pending', 'claim_number' => 'CLM-001']);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willThrowException(new PDOException('Deadlock'));

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($summaryStmt, $updateStmt) {
            return str_contains($sql, 'SELECT claim_id, status, claim_number') ? $summaryStmt : $updateStmt;
        });

        $service = new InsuranceService($pdo);
        $result = $service->reviewClaim(1, 'approve', 5);

        $this->assertFalse($result['success']);
        $this->assertSame('Could not update the claim. Please try again.', $result['message']);
    }
}