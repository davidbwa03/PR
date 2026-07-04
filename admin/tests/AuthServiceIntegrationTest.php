<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../AuthService.php';

/**
 * AuthServiceIntegrationTest
 *
 * This test connects to your REAL MariaDB database and really commits
 * data to the `administrators` table — unlike AuthServiceTest.php
 * (mocked, no DB) or a transaction-rolled-back version. You can watch
 * the row appear and disappear in phpMyAdmin while this runs.
 *
 * Safety approach used here since we are NOT using a transaction:
 *  - Every fake admin this test creates uses a unique, clearly-labeled
 *    email like integration_test_admin_<uniqid>@example.com.
 *  - tearDown() explicitly DELETEs only that one row by its exact email,
 *    every time, whether the test passed or failed.
 *  - Your real data (e.g. admin@gmail.com) is never queried, matched,
 *    or touched by any statement in this file.
 *
 * Requirements to run this file:
 *  - XAMPP's MySQL/MariaDB service must be running.
 *  - The `healthcare_middleware` database and `administrators` table
 *    must already exist with columns: admin_id, full_name, email, password.
 *
 * Note: if you stop the test mid-run (e.g. Ctrl+C), tearDown() won't get
 * a chance to run, and a stray "integration_test_admin_..." row could be
 * left in your administrators table. Safe to delete manually if you ever
 * spot one — nothing else in the app will reference it.
 */
final class AuthServiceIntegrationTest extends TestCase
{
    private PDO $pdo;

    /** @var string[] emails created during the current test, to clean up */
    private array $createdEmails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO(
            'mysql:host=localhost;dbname=healthcare_middleware;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        $this->createdEmails = [];
    }

    protected function tearDown(): void
    {
        // Real DELETE against the real table — but only for the exact
        // test rows this test created, matched by their unique emails.
        foreach ($this->createdEmails as $email) {
            $stmt = $this->pdo->prepare("DELETE FROM administrators WHERE email = ?");
            $stmt->execute([$email]);
        }

        parent::tearDown();
    }

    /**
     * Inserts a real, temporary admin row and tracks it for cleanup.
     */
    private function createTestAdmin(string $fullName, string $plainPassword): string
    {
        $email = 'integration_test_admin_' . uniqid() . '@example.com';
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        $insert = $this->pdo->prepare(
            "INSERT INTO administrators (full_name, email, password) VALUES (?, ?, ?)"
        );
        $insert->execute([$fullName, $email, $hash]);

        $this->createdEmails[] = $email;

        return $email;
    }

    public function testRealAdminCanLogInAgainstActualDatabase(): void
    {
        $email = $this->createTestAdmin('Integration Test Admin', 'temporary-test-password-123');

        $auth = new AuthService($this->pdo);
        $result = $auth->attemptLogin($email, 'temporary-test-password-123');

        $this->assertIsArray($result);
        $this->assertSame('Integration Test Admin', $result['full_name']);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function testWrongPasswordFailsAgainstRealDatabase(): void
    {
        $email = $this->createTestAdmin('Integration Test Admin', 'the-correct-password');

        $auth = new AuthService($this->pdo);
        $result = $auth->attemptLogin($email, 'wrong-password');

        $this->assertNull($result);
    }

    public function testUnknownEmailFailsAgainstActualDatabase(): void
    {
        $auth = new AuthService($this->pdo);
        $result = $auth->attemptLogin('definitely_not_a_real_admin_' . uniqid() . '@example.com', 'whatever');

        $this->assertNull($result);
    }
}