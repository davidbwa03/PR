<?php

/**
 * AdminService
 *
 * Centralizes the system-admin logic that previously lived inline
 * across admin/dashboard.php, add_hospital.php, edit_hospitals.php,
 * delete_hospitals.php, manage_hospitals.php, view_hospital.php,
 * view_doctors.php, view_patients.php, access_requests.php, and
 * reports.php:
 *   - hospital CRUD, including the linked staff account that gets
 *     created/synced/removed alongside an Active hospital
 *   - practitioner_id generation (MD-YYYY-NNNN) with collision checks
 *   - doctor/patient directory listings
 *   - access request moderation, tolerant of schema variations
 *     (status vs request_status, created_at vs requested_at)
 *   - dashboard counts and report summaries
 *
 * Usage:
 *   require_once 'db.php';           // provides $pdo
 *   require_once 'AdminService.php';
 *   $adminService = new AdminService($pdo);
 *   $stats = $adminService->getDashboardStats();
 */
class AdminService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create the hospitals/staff tables if they don't exist yet.
     * Mirrors the CREATE TABLE IF NOT EXISTS guards duplicated across
     * add_hospital.php and edit_hospitals.php.
     */
    public function ensureTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS hospitals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NOT NULL,
                county VARCHAR(100) NOT NULL,
                address VARCHAR(255) NOT NULL,
                status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_hospital_name (name),
                UNIQUE KEY unique_hospital_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS staff (
                id INT(11) NOT NULL AUTO_INCREMENT,
                practitioner_id VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                hospital_name VARCHAR(255) NULL,
                email VARCHAR(100) NOT NULL,
                phone VARCHAR(20) NULL,
                county VARCHAR(100) NULL,
                address TEXT NULL,
                status ENUM('Active', 'Inactive') DEFAULT 'Active',
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_staff_practitioner_id (practitioner_id),
                UNIQUE KEY unique_staff_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Attempt admin authentication using email and password.
     *
     * @return array<string, mixed>|null
     */
    public function attemptLogin(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT admin_id, full_name, email, password FROM administrators WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return null;
        }

        if (!password_verify($password, $admin['password'])) {
            return null;
        }

        return $admin;
    }

    // ------------------------------------------------------------------
    // Hospitals (add_hospital.php / edit_hospitals.php / delete_hospitals.php / manage_hospitals.php / view_hospital.php)
    // ------------------------------------------------------------------

    public function getAllHospitals(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM hospitals ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getHospitalById(int $hospitalId): ?array
    {
        if ($hospitalId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM hospitals WHERE id = ? LIMIT 1");
        $stmt->execute([$hospitalId]);
        $hospital = $stmt->fetch(PDO::FETCH_ASSOC);
        return $hospital ?: null;
    }

    /**
     * Generate the next available practitioner id in "MD-YYYY-NNNN"
     * format, skipping any that already exist (matches add_hospital.php's
     * collision-checking loop).
     */
    public function generateNextPractitionerId(): string
    {
        $currentYear = date('Y');
        $idPrefix = 'MD-' . $currentYear . '-';

        $lastIdStmt = $this->pdo->prepare(
            "SELECT practitioner_id FROM staff WHERE practitioner_id LIKE ? ORDER BY practitioner_id DESC LIMIT 1"
        );
        $lastIdStmt->execute([$idPrefix . '%']);
        $lastPractitionerId = $lastIdStmt->fetchColumn();

        $nextSequence = 1;
        if ($lastPractitionerId && preg_match('/^MD-\d{4}-(\d{4})$/', $lastPractitionerId, $matches)) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        $practitionerId = $idPrefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);

        $checkStmt = $this->pdo->prepare("SELECT id FROM staff WHERE practitioner_id = ?");
        while (true) {
            $checkStmt->execute([$practitionerId]);
            if ($checkStmt->rowCount() === 0) {
                break;
            }
            $nextSequence++;
            $practitionerId = $idPrefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
        }

        return $practitionerId;
    }

    /**
     * Add a new hospital. If status is 'Active', also provisions a
     * linked staff login account with a generated practitioner id.
     *
     * @param array $data Expected keys: name, email, phone, county,
     *   address, status, login_password.
     *
     * @return array{success: bool, message: string, practitioner_id?: string}
     */
    public function addHospital(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $county = trim($data['county'] ?? '');
        $address = trim($data['address'] ?? '');
        $status = ($data['status'] ?? 'Inactive') === 'Active' ? 'Active' : 'Inactive';
        $loginPassword = trim($data['login_password'] ?? '');
        $requiresStaffAccount = ($status === 'Active');

        if ($name === '' || $email === '' || $phone === '' || $county === '' || $address === '') {
            return ['success' => false, 'message' => 'Error: Please fill in all required fields.'];
        }

        if ($requiresStaffAccount && $loginPassword === '') {
            return ['success' => false, 'message' => 'Error: Staff login password is required for Active hospitals.'];
        }

        if ($requiresStaffAccount && strlen($loginPassword) < 6) {
            return ['success' => false, 'message' => 'Error: Staff login password must be at least 6 characters.'];
        }

        $checkHospital = $this->pdo->prepare("SELECT id FROM hospitals WHERE name = ? OR email = ?");
        $checkHospital->execute([$name, $email]);
        if ($checkHospital->rowCount() > 0) {
            return ['success' => false, 'message' => 'Error: A hospital with this name or email already exists.'];
        }

        try {
            $practitionerId = '';

            if ($requiresStaffAccount) {
                $checkStaff = $this->pdo->prepare("SELECT id FROM staff WHERE email = ?");
                $checkStaff->execute([$email]);
                if ($checkStaff->rowCount() > 0) {
                    throw new RuntimeException('A staff account with this email already exists.');
                }
            }

            $this->pdo->beginTransaction();

            $stmtHospital = $this->pdo->prepare(
                "INSERT INTO hospitals (name, email, phone, county, address, status) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmtHospital->execute([$name, $email, $phone, $county, $address, $status]);

            if ($requiresStaffAccount) {
                $practitionerId = $this->generateNextPractitionerId();
                $hashedPassword = password_hash($loginPassword, PASSWORD_BCRYPT);

                $stmtStaff = $this->pdo->prepare(
                    "INSERT INTO staff (practitioner_id, name, hospital_name, email, phone, county, address, status, password)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmtStaff->execute([$practitionerId, $name, $name, $email, $phone, $county, $address, $status, $hashedPassword]);
            }

            $this->pdo->commit();

            if ($requiresStaffAccount) {
                return [
                    'success' => true,
                    'message' => "Hospital and staff login created successfully. Login Email: {$email} | Practitioner ID: {$practitionerId}",
                    'practitioner_id' => $practitionerId,
                ];
            }

            return ['success' => true, 'message' => 'Inactive hospital created successfully. No staff account was created.'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: Could not complete registration. ' . $e->getMessage()];
        }
    }

    /**
     * Update an existing hospital and sync its linked staff account
     * (matched by the hospital's previous email or name).
     *
     * @return array{success: bool, message: string}
     */
    public function updateHospital(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $county = trim($data['county'] ?? '');
        $address = trim($data['address'] ?? '');
        $status = ($data['status'] ?? 'Inactive') === 'Active' ? 'Active' : 'Inactive';

        if ($id <= 0 || $name === '' || $email === '' || $phone === '' || $county === '' || $address === '') {
            return ['success' => false, 'message' => 'Error: Please complete all hospital fields before saving.'];
        }

        try {
            $stmtExisting = $this->pdo->prepare("SELECT name, email FROM hospitals WHERE id = ? LIMIT 1");
            $stmtExisting->execute([$id]);
            $existingHospital = $stmtExisting->fetch(PDO::FETCH_ASSOC);

            if (!$existingHospital) {
                throw new RuntimeException('Hospital not found.');
            }

            $this->pdo->beginTransaction();

            $stmtHospital = $this->pdo->prepare(
                "UPDATE hospitals SET name = ?, email = ?, phone = ?, county = ?, address = ?, status = ? WHERE id = ?"
            );
            $stmtHospital->execute([$name, $email, $phone, $county, $address, $status, $id]);

            $stmtStaffSync = $this->pdo->prepare(
                "UPDATE staff SET name = ?, hospital_name = ?, email = ?, phone = ?, county = ?, address = ?, status = ?
                 WHERE email = ? OR hospital_name = ?"
            );
            $stmtStaffSync->execute([
                $name, $name, $email, $phone, $county, $address, $status,
                $existingHospital['email'], $existingHospital['name'],
            ]);

            $this->pdo->commit();

            if ($stmtStaffSync->rowCount() > 0) {
                return ['success' => true, 'message' => 'Hospital updated successfully and staff account synced.'];
            }

            return ['success' => true, 'message' => 'Hospital updated successfully. No linked staff account found to sync.'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a hospital and any linked staff accounts (matched by the
     * hospital's email or name).
     *
     * @return array{success: bool, message: string}
     */
    public function deleteHospital(int $hospitalId): array
    {
        if ($hospitalId <= 0) {
            return ['success' => false, 'message' => 'Error: Invalid hospital ID.'];
        }

        try {
            $stmtHospital = $this->pdo->prepare("SELECT id, name, email FROM hospitals WHERE id = ? LIMIT 1");
            $stmtHospital->execute([$hospitalId]);
            $hospital = $stmtHospital->fetch(PDO::FETCH_ASSOC);

            if (!$hospital) {
                return ['success' => false, 'message' => 'Hospital not found or already deleted.'];
            }

            $this->pdo->beginTransaction();

            $stmtStaff = $this->pdo->prepare("DELETE FROM staff WHERE email = ? OR hospital_name = ?");
            $stmtStaff->execute([$hospital['email'], $hospital['name']]);
            $deletedStaffCount = $stmtStaff->rowCount();

            $stmtDelete = $this->pdo->prepare("DELETE FROM hospitals WHERE id = ?");
            $stmtDelete->execute([$hospitalId]);

            if ($stmtDelete->rowCount() === 1) {
                $this->pdo->commit();
                $message = 'Hospital deleted successfully.';
                if ($deletedStaffCount > 0) {
                    $message .= ' Linked staff deleted: ' . $deletedStaffCount . '.';
                }
                return ['success' => true, 'message' => $message];
            }

            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Error: Delete did not affect any SQL row.'];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: Could not delete hospital from SQL. ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Directories (view_doctors.php / view_patients.php)
    // ------------------------------------------------------------------

    public function getAllDoctors(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, specialty, hospital_name, email, phone, status FROM doctors");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllPatients(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT id, name, email, phone, gender, dob FROM patients ORDER BY id DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Access requests (access_requests.php)
    // ------------------------------------------------------------------

    /**
     * Detect which status/date column names this install's
     * access_requests table actually uses.
     *
     * @return array{status_column: ?string, order_by: string, columns: string[]}
     */
    public function detectAccessRequestsSchema(): array
    {
        $columns = [];
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM access_requests")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            $columns = [];
        }

        $statusColumn = null;
        if (in_array('status', $columns, true)) {
            $statusColumn = 'status';
        } elseif (in_array('request_status', $columns, true)) {
            $statusColumn = 'request_status';
        }

        $orderBy = 'id DESC';
        if (in_array('created_at', $columns, true)) {
            $orderBy = 'created_at DESC';
        } elseif (in_array('requested_at', $columns, true)) {
            $orderBy = 'requested_at DESC';
        }

        return ['status_column' => $statusColumn, 'order_by' => $orderBy, 'columns' => $columns];
    }

    /**
     * All access requests, ordered using whichever date column exists.
     */
    public function getAllAccessRequests(): array
    {
        $schema = $this->detectAccessRequestsSchema();
        $stmt = $this->pdo->query("SELECT * FROM access_requests ORDER BY {$schema['order_by']}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approve or reject an access request, writing to whichever status
     * column this install actually has.
     *
     * @return array{success: bool, message: string}
     */
    public function updateAccessRequestStatus(int $requestId, string $action): array
    {
        if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            return ['success' => false, 'message' => 'Invalid access request action.'];
        }

        $schema = $this->detectAccessRequestsSchema();
        if ($schema['status_column'] === null) {
            return ['success' => false, 'message' => 'Could not determine the status column for access requests.'];
        }

        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
        $statusColumn = $schema['status_column'];

        $stmt = $this->pdo->prepare("UPDATE access_requests SET `{$statusColumn}` = ? WHERE id = ?");
        $stmt->execute([$newStatus, $requestId]);

        return ['success' => true, 'message' => 'Request updated successfully.'];
    }

    /**
     * Map of lowercase doctor name => lowercase account status, used to
     * cross-reference an access request's doctor_name against the
     * doctor's actual account status.
     */
    public function getDoctorStatusByName(): array
    {
        $doctorStatusByName = [];
        try {
            $doctorColumns = $this->pdo->query("SHOW COLUMNS FROM doctors")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (in_array('name', $doctorColumns, true) && in_array('status', $doctorColumns, true)) {
                $doctorRows = $this->pdo->query("SELECT name, status FROM doctors")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($doctorRows as $doctorRow) {
                    $nameKey = strtolower(trim((string) ($doctorRow['name'] ?? '')));
                    if ($nameKey !== '') {
                        $doctorStatusByName[$nameKey] = strtolower(trim((string) ($doctorRow['status'] ?? '')));
                    }
                }
            }
        } catch (PDOException $e) {
            $doctorStatusByName = [];
        }

        return $doctorStatusByName;
    }

    // ------------------------------------------------------------------
    // Dashboard / reports (dashboard.php / reports.php)
    // ------------------------------------------------------------------

    /**
     * Run a COUNT(*) query, returning 0 on any failure rather than
     * throwing (matches dashboard.php's safeCount() helper).
     */
    public function safeCount(string $sql): int
    {
        try {
            return (int) $this->pdo->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function getDashboardStats(): array
    {
        return [
            'total_hospitals' => $this->safeCount("SELECT COUNT(*) FROM hospitals"),
            'total_doctors' => $this->safeCount("SELECT COUNT(*) FROM doctors"),
            'total_patients' => $this->safeCount("SELECT COUNT(*) FROM patients"),
            'pending_requests' => $this->safeCount("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'"),
        ];
    }

    public function getSystemStats(): array
    {
        return [
            'hospitals' => (int) $this->pdo->query("SELECT COUNT(*) FROM hospitals")->fetchColumn(),
            'doctors' => (int) $this->pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn(),
            'patients' => (int) $this->pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn(),
        ];
    }

    public function getRecentDoctors(int $limit = 5): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->query("SELECT name, specialty, status FROM doctors ORDER BY created_at DESC LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}