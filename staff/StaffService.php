<?php

/**
 * StaffService
 *
 * Centralizes the hospital-staff (admin) logic that previously lived
 * inline across staff/dashboard.php, add_practitioner.php,
 * delete_doctor.php, manage_practitioners.php, patient_requests.php,
 * update_vitals.php, reset_password.php, and analytics.php:
 *   - resolving the staff member's hospital brand name for the UI
 *   - adding / removing / listing doctors, with graceful handling of
 *     installs where the `doctors.status` column doesn't exist yet
 *   - resolving a "patient reference" (PT-YYYY-ID, raw id, national id,
 *     or email) to an actual patient row — used by both the vitals
 *     page and the "request patient summary" dashboard widget
 *   - creating / fulfilling access_requests (data requests to patients,
 *     marking records as sent to a doctor)
 *   - recording vitals-only medical_records entries
 *   - resetting a staff member's own password
 *   - dashboard/analytics counts (practitioners, patients, requests,
 *     trends, status distribution, role distribution)
 *
 * Usage:
 *   require_once 'db.php';           // provides $pdo
 *   require_once 'StaffService.php';
 *   $staffService = new StaffService($pdo);
 *   $hospitalName = $staffService->getHospitalName($staff_id);
 */
class StaffService
{
    private PDO $pdo;
    private bool $doctorsHaveStatusColumn = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->detectSchema();
    }

    private function detectSchema(): void
    {
        try {
            $colCheck = $this->pdo->query("SHOW COLUMNS FROM doctors LIKE 'status'");
            $this->doctorsHaveStatusColumn = (bool) ($colCheck && $colCheck->fetch(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->doctorsHaveStatusColumn = false;
        }
    }

    public function doctorsHaveStatusColumn(): bool
    {
        return $this->doctorsHaveStatusColumn;
    }

    // ------------------------------------------------------------------
    // Hospital branding
    // ------------------------------------------------------------------

    /**
     * Resolve the display name of the staff member's hospital, falling
     * back to a generic label if unset or on any DB error.
     */
    public function getHospitalName(int $staffId, string $fallback = 'Hospital'): string
    {
        if ($staffId <= 0) {
            return $fallback;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT hospital_name FROM staff WHERE id = ? LIMIT 1");
            $stmt->execute([$staffId]);
            $resolved = trim((string) $stmt->fetchColumn());
            return $resolved !== '' ? $resolved : $fallback;
        } catch (PDOException $e) {
            return $fallback;
        }
    }

    // ------------------------------------------------------------------
    // Practitioner management (add_practitioner.php / delete_doctor.php / manage_practitioners.php)
    // ------------------------------------------------------------------

    /**
     * Add a new doctor account.
     *
     * @param array $data Expected keys: name, specialty, email, password,
     *   phone, dob, gender, address.
     *
     * @return array{success: bool, message: string}
     */
    public function addPractitioner(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $specialty = trim($data['specialty'] ?? '');
        $email = trim($data['email'] ?? '');
        $passwordRaw = $data['password'] ?? '';
        $phone = trim($data['phone'] ?? '');
        $dob = trim($data['dob'] ?? '');
        $gender = trim($data['gender'] ?? '');
        $address = trim($data['address'] ?? '');

        $hashedPassword = password_hash($passwordRaw, PASSWORD_BCRYPT);

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO doctors (name, specialty, email, password, phone, dob, gender, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $specialty, $email, $hashedPassword, $phone, $dob, $gender, $address]);

            return ['success' => true, 'message' => 'Doctor added successfully.'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: Could not add doctor. The email might already be registered.'];
        }
    }

    /**
     * Delete a doctor account by id.
     */
    public function deleteDoctor(int $doctorId): bool
    {
        if ($doctorId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM doctors WHERE id = ?");
        return $stmt->execute([$doctorId]);
    }

    /**
     * List all doctors. If the `status` column doesn't exist on this
     * install, every row is reported as 'inactive' (matching the
     * original page's fallback behavior) rather than failing.
     */
    public function getDoctors(): array
    {
        $query = $this->doctorsHaveStatusColumn
            ? "SELECT id, name, specialty, status FROM doctors ORDER BY name ASC"
            : "SELECT id, name, specialty, 'inactive' AS status FROM doctors ORDER BY name ASC";

        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a doctor's active/inactive status.
     *
     * @return array{success: bool, message: string}
     */
    public function updateDoctorStatus(int $doctorId, string $status): array
    {
        if (!$this->doctorsHaveStatusColumn) {
            return ['success' => false, 'message' => 'Status column is not available in the doctors table.'];
        }

        $normalizedStatus = $status === 'active' ? 'active' : 'inactive';

        $stmt = $this->pdo->prepare("UPDATE doctors SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $normalizedStatus, 'id' => $doctorId]);

        return ['success' => true, 'message' => 'Practitioner status updated successfully.'];
    }

    // ------------------------------------------------------------------
    // Patient reference resolution (shared by dashboard + vitals pages)
    // ------------------------------------------------------------------

    /**
     * Resolve a free-text "patient reference" — a raw numeric id, a
     * "PT-YYYY-ID" formatted reference, a national id, or an email —
     * into a patient row. Returns null if nothing matches.
     */
    public function resolvePatientReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $patientId = null;
        $matches = [];

        if (preg_match('/^PT-\d{4}-(\d+)$/i', $reference, $matches)) {
            $patientId = (int) $matches[1];
        } elseif (ctype_digit($reference)) {
            $patientId = (int) $reference;
        }

        if ($patientId !== null && $patientId > 0) {
            $stmt = $this->pdo->prepare("SELECT id, name FROM patients WHERE id = ? LIMIT 1");
            $stmt->execute([$patientId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id, name FROM patients WHERE national_id = ? OR email = ? LIMIT 1");
            $stmt->execute([$reference, $reference]);
        }

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        return $patient ?: null;
    }

    // ------------------------------------------------------------------
    // Access requests (patient_requests.php / dashboard.php)
    // ------------------------------------------------------------------

    /**
     * All access requests with joined patient details, newest first.
     * Callers are expected to hide patient details for non-approved
     * rows themselves (as patient_requests.php does), since whether
     * to reveal that data is a presentation decision, not a data one.
     */
    public function getAllPatientRequests(): array
    {
        $stmt = $this->pdo->query("
            SELECT ar.id, ar.patient_id, ar.request_status,
                   p.name AS patient_name,
                   p.email AS patient_email,
                   p.national_id AS patient_national_id,
                   p.phone AS patient_phone,
                   p.dob AS patient_dob,
                   p.gender AS patient_gender
            FROM access_requests ar
            LEFT JOIN patients p ON p.id = ar.patient_id
            ORDER BY ar.requested_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Request a patient data summary by free-text reference (dashboard
     * "Request Patient Summary" widget). Creates a pending access_requests
     * row on the staff member's behalf.
     *
     * @return array{success: bool, message: string}
     */
    public function requestPatientSummary(string $reference, string $staffName, string $hospitalName): array
    {
        if ($reference === '') {
            return ['success' => false, 'message' => 'Please provide a patient reference.'];
        }

        $patient = $this->resolvePatientReference($reference);

        if (!$patient) {
            return ['success' => false, 'message' => 'No patient matched the provided reference. Use Patient DB ID, National ID, or Email.'];
        }

        $facilityName = $hospitalName !== '' ? $hospitalName : 'Central Medical Center';

        $stmt = $this->pdo->prepare(
            "INSERT INTO access_requests (patient_id, doctor_name, medical_facility, request_status, requested_at) VALUES (?, ?, ?, 'pending', NOW())"
        );
        $stmt->execute([(int) $patient['id'], $staffName, $facilityName]);

        return ['success' => true, 'message' => "Summary request for {$reference} has been initiated."];
    }

    /**
     * Request patient data directly on behalf of a named doctor/facility
     * (the original "existing" data-request form on the dashboard).
     *
     * @return array{success: bool, message: string}
     */
    public function requestPatientData(int $patientId, string $doctorName, string $medicalFacility): array
    {
        if ($patientId <= 0 || $doctorName === '' || $medicalFacility === '') {
            return ['success' => false, 'message' => 'Patient, doctor name, and medical facility are required.'];
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO access_requests (patient_id, doctor_name, medical_facility, request_status, requested_at) VALUES (?, ?, ?, 'pending', NOW())"
        );
        $stmt->execute([$patientId, $doctorName, $medicalFacility]);

        return ['success' => true, 'message' => 'Data request successfully sent to the patient.'];
    }

    /**
     * Mark an approved access request's records as sent to the doctor.
     *
     * @return array{success: bool, message: string}
     */
    public function sendRecordsToDoctor(int $requestId): array
    {
        if ($requestId <= 0) {
            return ['success' => false, 'message' => 'Invalid request.'];
        }

        $requestStmt = $this->pdo->prepare("SELECT patient_id, doctor_name FROM access_requests WHERE id = ? AND request_status = 'approved' LIMIT 1");
        $requestStmt->execute([$requestId]);
        $requestDetails = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $patientId = (int) ($requestDetails['patient_id'] ?? 0);
        $doctorName = trim((string) ($requestDetails['doctor_name'] ?? ''));

        if ($patientId <= 0 || $doctorName === '') {
            return ['success' => false, 'message' => 'Cannot send records while this request is still pending or declined.'];
        }

        $duplicateStmt = $this->pdo->prepare("SELECT 1 FROM access_requests WHERE patient_id = ? AND doctor_name = ? AND records_sent = 1 LIMIT 1");
        $duplicateStmt->execute([$patientId, $doctorName]);
        if ((bool) $duplicateStmt->fetchColumn()) {
            return ['success' => false, 'message' => "This doctor already has this patient's details."];
        }

        $stmt = $this->pdo->prepare("UPDATE access_requests SET records_sent = 1, updated_at = NOW() WHERE id = ? AND request_status = 'approved' AND (records_sent IS NULL OR records_sent = 0)");
        $stmt->execute([$requestId]);

        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'Cannot send records while this request is still pending or declined.'];
        }

        return ['success' => true, 'message' => 'Medical records successfully sent to the doctor.'];
    }

    // ------------------------------------------------------------------
    // Vitals (update_vitals.php)
    // ------------------------------------------------------------------

    /**
     * Record a vitals-only medical_records entry for a patient, resolved
     * by free-text reference.
     *
     * @param array $data Expected keys: patient_ref, blood_pressure,
     *   heart_rate, temperature, vitals_date, notes.
     *
     * @return array{success: bool, message: string}
     */
    public function updateVitals(array $data, string $staffName, string $hospitalName): array
    {
        $patientRef = trim($data['patient_ref'] ?? '');
        $bloodPressure = trim($data['blood_pressure'] ?? '');
        $heartRate = trim($data['heart_rate'] ?? '');
        $temperature = trim($data['temperature'] ?? '');
        $vitalsDate = trim($data['vitals_date'] ?? '');
        $notes = trim($data['notes'] ?? '');

        if ($patientRef === '' || $bloodPressure === '' || $heartRate === '' || $temperature === '' || $vitalsDate === '') {
            return ['success' => false, 'message' => 'Patient reference, blood pressure, heart rate, temperature, and vitals date are required.'];
        }

        $validDate = DateTime::createFromFormat('Y-m-d', $vitalsDate);
        if (!$validDate || $validDate->format('Y-m-d') !== $vitalsDate) {
            return ['success' => false, 'message' => 'Invalid vitals date. Use YYYY-MM-DD format.'];
        }

        $patient = $this->resolvePatientReference($patientRef);
        if (!$patient) {
            return ['success' => false, 'message' => 'No patient matched the provided reference.'];
        }

        $facilityName = $hospitalName !== '' ? $hospitalName : 'Central Medical Center';
        $clinicalNote = $notes === '' ? 'Vitals update captured by hospital staff.' : $notes;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO medical_records (
                    patient_id, visit_type, hospital_name, visit_date, notes,
                    created_by, blood_pressure, heart_rate, temperature, clinical_notes
                ) VALUES (
                    :pid, :visit_type, :hospital, :visit_date, :notes,
                    :created_by, :blood_pressure, :heart_rate, :temperature, :clinical_notes
                )
            ");
            $stmt->execute([
                'pid' => (int) $patient['id'],
                'visit_type' => 'Vitals Check',
                'hospital' => $facilityName,
                'visit_date' => $vitalsDate,
                'notes' => $notes,
                'created_by' => $staffName,
                'blood_pressure' => $bloodPressure,
                'heart_rate' => $heartRate,
                'temperature' => $temperature,
                'clinical_notes' => $clinicalNote,
            ]);

            return ['success' => true, 'message' => 'Vitals were saved for ' . $patient['name'] . '.'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Could not save vitals right now. Please try again.'];
        }
    }

    // ------------------------------------------------------------------
    // Password reset (reset_password.php)
    // ------------------------------------------------------------------

    /**
     * Reset a staff member's own password after OTP verification.
     *
     * @return array{success: bool, message: string}
     */
    public function resetStaffPassword(int $staffId, string $password, string $confirmPassword): array
    {
        if ($password !== $confirmPassword) {
            return ['success' => false, 'message' => 'Form credentials do not match.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Password must comprise at least 8 elements.'];
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare("UPDATE staff SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $staffId]);

        return ['success' => true, 'message' => 'Password updated successfully.'];
    }

    // ------------------------------------------------------------------
    // Dashboard metrics
    // ------------------------------------------------------------------

    public function getActivePractitionersCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT (SELECT COUNT(*) FROM staff) + (SELECT COUNT(*) FROM doctors)");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Patients registered today, falling back to total patient count
     * when there are none today (matches dashboard.php's behavior so
     * the metric card isn't just a wall of zeroes on a quiet day).
     */
    public function getPatientsTodayCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()");
            $count = (int) $stmt->fetchColumn();

            if ($count === 0) {
                $totalStmt = $this->pdo->query("SELECT COUNT(*) FROM patients");
                $count = (int) $totalStmt->fetchColumn();
            }

            return $count;
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function getDataRequestsCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM access_requests");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function getPractitionersList(int $limit = 3): array
    {
        $limit = max(1, $limit);
        try {
            $stmt = $this->pdo->query(
                "SELECT id, name, 'Hospital Staff' AS specialty, 'staff' AS type FROM staff
                 UNION ALL
                 SELECT id, name, specialty, 'doctors' AS type FROM doctors
                 ORDER BY name ASC
                 LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getRecentRequests(int $limit = 10): array
    {
        $limit = max(1, $limit);
        try {
            $stmt = $this->pdo->query(
                "SELECT id, patient_id, doctor_name, medical_facility, request_status, requested_at, records_sent
                 FROM access_requests ORDER BY requested_at DESC LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Analytics (analytics.php)
    // ------------------------------------------------------------------

    public function getPatientTrends(int $limit = 6): array
    {
        $limit = max(1, $limit);
        try {
            $stmt = $this->pdo->query(
                "SELECT DATE_FORMAT(created_at, '%M') as month, COUNT(*) as total
                 FROM patients GROUP BY MONTH(created_at) ORDER BY MONTH(created_at) DESC LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getRequestStatusDistribution(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT request_status, COUNT(*) as count FROM access_requests GROUP BY request_status");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getRoleDistribution(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT 'Staff' as role, COUNT(*) as count FROM staff
                 UNION SELECT 'Doctors', COUNT(*) FROM doctors
                 UNION SELECT 'Patients', COUNT(*) FROM patients"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}