<?php

/**
 * PatientService
 *
 * Centralizes the patient-facing database logic that previously lived
 * inline across privacy_settings.php, process_access.php, register.php,
 * medicalrecord.php, and current_health.php:
 *   - looking up a patient by session email
 *   - listing / approving / declining incoming access requests from
 *     hospital admins (privacy_settings.php + process_access.php)
 *   - registering a new patient account (register.php)
 *   - fetching medical records, prescriptions, and doctor-authored
 *     privacy consent summaries (medicalrecord.php)
 *   - fetching the latest vitals snapshot for the dashboard
 *     (current_health.php)
 *
 * Usage:
 *   require_once 'db.php';             // provides $pdo
 *   require_once 'PatientService.php';
 *   $patientService = new PatientService($pdo);
 *   $patient = $patientService->getPatientByEmail($_SESSION['patient']);
 */
class PatientService
{
    /** @var string[] Allowed values for processAccessRequest()'s $action */
    private const ALLOWED_ACCESS_ACTIONS = ['approved', 'declined'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    /**
     * Create/upgrade any supporting tables this service depends on.
     * Mirrors the CREATE TABLE IF NOT EXISTS / ALTER TABLE guards that
     * were previously duplicated in privacy_settings-adjacent pages.
     */
    private function ensureTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS patient_privacy_consents (
            patient_id INT NOT NULL PRIMARY KEY,
            allergies_summary_text TEXT NULL,
            chronic_diagnostic_logs_text TEXT NULL,
            surgical_typologies_summary_text TEXT NULL,
            surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0,
            authored_by_doctor_id INT NULL,
            authored_by_doctor_name VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $alters = [
            "ALTER TABLE patient_privacy_consents ADD COLUMN allergies_summary_text TEXT NULL",
            "ALTER TABLE patient_privacy_consents ADD COLUMN chronic_diagnostic_logs_text TEXT NULL",
            "ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_summary_text TEXT NULL",
            "ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_name VARCHAR(255) NULL",
        ];
        foreach ($alters as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                // Column already exists — ignore.
            }
        }
    }

    // ------------------------------------------------------------------
    // Patient profile lookup
    // ------------------------------------------------------------------

    /**
     * Look up a patient by their session email (id, name, national_id).
     * Used by privacy_settings.php / current_health.php / medicalrecord.php.
     */
    public function getPatientByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, name, national_id FROM patients WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        return $patient ?: null;
    }

    /**
     * Full patient row lookup by id.
     */
    public function getPatientById(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM patients WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $patientId]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        return $patient ?: null;
    }

    // ------------------------------------------------------------------
    // Access requests (privacy_settings.php / process_access.php)
    // ------------------------------------------------------------------

    /**
     * Pending hospital-admin access requests awaiting this patient's
     * decision, newest first.
     */
    public function getPendingAccessRequests(int $patientId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, doctor_name AS hospital_admin_name, medical_facility, requested_at
            FROM access_requests
            WHERE patient_id = :pid AND request_status = 'pending'
            ORDER BY requested_at DESC
        ");
        $stmt->execute(['pid' => $patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approve or decline a pending access request.
     *
     * @return array{success: bool, message: string}
     */
    public function processAccessRequest(int $requestId, string $action): array
    {
        if ($requestId <= 0 || !in_array($action, self::ALLOWED_ACCESS_ACTIONS, true)) {
            return ['success' => false, 'message' => 'Invalid access request action.'];
        }

        $stmt = $this->pdo->prepare("UPDATE access_requests SET request_status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$action, $requestId]);

        return ['success' => true, 'message' => "Access request has been {$action}."];
    }

    // ------------------------------------------------------------------
    // Registration (register.php)
    // ------------------------------------------------------------------

    /**
     * Register a new patient account.
     *
     * @param array $data Expected keys: full_name, national_id, email,
     *   password, confirm_password, dob, gender, blood_group, phone.
     *
     * @return array{success: bool, message: string, patient_id?: int, otp_code?: string}
     *   On success, includes the newly created patient_id and the
     *   generated OTP code so the caller can store it in the session
     *   and email it (this service does not send email itself).
     */
    public function registerPatient(array $data): array
    {
        $fullName = trim($data['full_name'] ?? '');
        $nationalId = trim($data['national_id'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';
        $dob = $data['dob'] ?? '';
        $gender = $data['gender'] ?? '';
        $bloodGroup = $data['blood_group'] ?? '';
        $phone = trim($data['phone'] ?? '');

        if ($password !== $confirmPassword) {
            return ['success' => false, 'message' => 'Passwords do not match!'];
        }

        try {
            $stmt = $this->pdo->prepare("SELECT id FROM patients WHERE email = ? OR national_id = ?");
            $stmt->execute([$email, $nationalId]);

            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'An account with this email or National ID already exists.'];
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $otpCode = (string) random_int(100000, 999999);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);

            $insertStmt = $this->pdo->prepare("
                INSERT INTO patients (name, national_id, dob, gender, blood_group, phone, email, password, email_2fa_code, two_fa_expires_at)
                VALUES (:name, :national_id, :dob, :gender, :blood_group, :phone, :email, :password, :otp, :expires)
            ");
            $insertStmt->execute([
                ':name' => $fullName,
                ':national_id' => $nationalId,
                ':dob' => $dob,
                ':gender' => $gender,
                ':blood_group' => $bloodGroup,
                ':phone' => $phone,
                ':email' => $email,
                ':password' => $hashedPassword,
                ':otp' => $otpCode,
                ':expires' => $expiresAt,
            ]);

            return [
                'success' => true,
                'message' => 'Registration successful.',
                'patient_id' => (int) $this->pdo->lastInsertId(),
                'otp_code' => $otpCode,
            ];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'System registration error: ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Medical records / prescriptions / privacy consents (medicalrecord.php)
    // ------------------------------------------------------------------

    /**
     * Medical history records, excluding vitals-only entries (those are
     * surfaced separately via getLatestVitalsRecord() on the Current
     * Health page).
     */
    public function getMedicalRecords(int $patientId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM medical_records
            WHERE patient_id = ? AND LOWER(COALESCE(visit_type, '')) NOT IN ('vitals check', 'vitals update')
            ORDER BY visit_date DESC
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPrescriptions(int $patientId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM medication_prescriptions WHERE patient_id = ? ORDER BY id DESC");
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Doctor-authored privacy consent summaries, with patient-facing
     * placeholder text when none has been written yet.
     */
    public function getPrivacyConsents(int $patientId): array
    {
        $default = [
            'allergies_summary_text' => 'No doctor summary provided yet.',
            'chronic_diagnostic_logs_text' => 'No doctor summary provided yet.',
            'surgical_typologies_summary_text' => 'No doctor summary provided yet.',
            'surgical_typologies_necessary' => 0,
            'authored_by_doctor_name' => null,
            'updated_at' => null,
        ];

        $stmt = $this->pdo->prepare("
            SELECT allergies_summary_text, chronic_diagnostic_logs_text,
                   surgical_typologies_summary_text, surgical_typologies_necessary,
                   authored_by_doctor_name, updated_at
            FROM patient_privacy_consents
            WHERE patient_id = ?
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: $default;
    }

    // ------------------------------------------------------------------
    // Vitals (current_health.php)
    // ------------------------------------------------------------------

    /**
     * The most recent medical_records row that carries at least one
     * vitals metric (blood pressure, heart rate, or temperature).
     */
    public function getLatestVitalsRecord(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT visit_type, visit_date, created_at, clinical_notes, notes, medications_prescribed,
                   hospital_name, blood_pressure, heart_rate, temperature, created_by
            FROM medical_records
            WHERE patient_id = :patient_id
              AND (
                    (blood_pressure IS NOT NULL AND blood_pressure <> '')
                 OR (heart_rate IS NOT NULL AND heart_rate <> '')
                 OR (temperature IS NOT NULL AND temperature <> '')
              )
            ORDER BY COALESCE(visit_date, created_at) DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute(['patient_id' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Most recent non-empty value for a single vitals column, so one
     * missing metric doesn't hide the others (mirrors the three
     * separate lookups current_health.php previously ran inline).
     *
     * @param string $column One of: blood_pressure, heart_rate, temperature.
     */
    public function getLatestMetricValue(int $patientId, string $column): ?string
    {
        $allowedColumns = ['blood_pressure', 'heart_rate', 'temperature'];
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException("Unsupported vitals column: {$column}");
        }

        $stmt = $this->pdo->prepare("
            SELECT `{$column}` FROM medical_records
            WHERE patient_id = :patient_id AND `{$column}` IS NOT NULL AND `{$column}` <> ''
            ORDER BY COALESCE(visit_date, created_at) DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute(['patient_id' => $patientId]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null) ? (string) $value : null;
    }
}