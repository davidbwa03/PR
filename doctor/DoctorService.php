<?php
/**
 * DoctorService.php
 *
 * Centralizes all doctor-facing database logic that was previously
 * duplicated across my_patients.php, patient_records.php, and
 * update_records.php:
 *   - doctor profile lookup
 *   - assigned/approved patient listing
 *   - per-patient access verification (consent-based access control)
 *   - patient detail / medical record / prescription / allergy retrieval
 *   - inserting medical records, prescriptions, and privacy consent summaries
 *
 * Usage:
 *   require_once 'db.php';          // provides $pdo
 *   require_once 'DoctorService.php';
 *   $doctorService = new DoctorService($pdo);
 *   $doctor = $doctorService->getDoctor($doctor_id);
 */

class DoctorService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    /**
     * Create any supporting tables this service depends on if they
     * don't already exist. Mirrors the CREATE TABLE IF NOT EXISTS /
     * ALTER TABLE guards previously scattered across each page.
     */
    private function ensureTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS patient_allergies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            allergen_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_patient_allergen (patient_id, allergen_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

        // Defensive ALTERs in case an older version of the table exists
        // without one of these columns (safe no-ops if already present).
        $alters = [
            "ALTER TABLE patient_privacy_consents ADD COLUMN allergies_summary_text TEXT NULL",
            "ALTER TABLE patient_privacy_consents ADD COLUMN chronic_diagnostic_logs_text TEXT NULL",
            "ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_summary_text TEXT NULL",
            "ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_id INT NULL",
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
    // Doctor profile
    // ------------------------------------------------------------------

    /**
     * Fetch the logged-in doctor's profile row.
     */
    public function getDoctor(int $doctorId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM doctors WHERE id = :id");
        $stmt->execute(['id' => $doctorId]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
        return $doctor ?: null;
    }

    /**
     * Convenience helper: doctor specialty with a sensible fallback,
     * matching the "General Practitioner" / "Doctor" defaults used
     * on the existing pages.
     */
    public function getDoctorSpecialty(int $doctorId, string $default = 'General Practitioner'): string
    {
        $stmt = $this->pdo->prepare("SELECT specialty FROM doctors WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $doctorId]);
        $specialty = $stmt->fetchColumn();
        return !empty($specialty) ? $specialty : $default;
    }

    // ------------------------------------------------------------------
    // Patient listing (My Patients)
    // ------------------------------------------------------------------

    /**
     * All patients assigned to this doctor by the hospital admin
     * (approved access_requests where records have been sent),
     * matched by doctor_name — same join used in my_patients.php.
     */
    public function getAssignedPatients(string $doctorName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                   p.id            AS patient_id,
                   p.name          AS patient_name,
                   p.national_id,
                   p.email         AS patient_email,
                   ar.requested_at AS assigned_at,
                (SELECT COUNT(*) FROM medical_records WHERE patient_id = p.id) AS record_count,
                (SELECT COUNT(*) FROM patient_allergies WHERE patient_id = p.id) AS allergy_count
            FROM access_requests ar
            JOIN patients p ON p.id = ar.patient_id
            WHERE ar.doctor_name = :dname
              AND ar.request_status = 'approved'
              AND ar.records_sent = 1
            ORDER BY ar.requested_at DESC
        ");
        $stmt->execute(['dname' => $doctorName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Same list as above but joined via doctors.id instead of doctor
     * name, matching the pattern used in update_records.php (avoids
     * session name mismatch issues).
     */
    public function getAssignedPatientsByDoctorId(int $doctorId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT p.id, p.name, p.national_id, p.email
            FROM access_requests ar
            JOIN patients p ON p.id = ar.patient_id
            JOIN doctors d ON d.name = ar.doctor_name
            WHERE d.id = :did
              AND ar.request_status = 'approved'
              AND ar.records_sent = 1
            ORDER BY p.name ASC
        ");
        $stmt->execute(['did' => $doctorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    /**
     * Confirm this doctor has an APPROVED, records-sent access request
     * for the given patient (by doctor_name). Returns the access_requests
     * row (with consent metadata) or null if access isn't granted.
     */
    public function verifyAccessByName(string $doctorName, int $patientId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, doctor_name, medical_facility, request_status, records_sent, requested_at, updated_at
            FROM access_requests
            WHERE doctor_name = ? AND patient_id = ? AND request_status = 'approved' AND records_sent = 1
            LIMIT 1
        ");
        $stmt->execute([$doctorName, $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Same check, joined via doctor id (used before writing new records
     * / prescriptions so a doctor can't post to a patient they were
     * never granted access to).
     */
    public function verifyAccessById(int $doctorId, int $patientId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT ar.id
            FROM access_requests ar
            JOIN doctors d ON d.name = ar.doctor_name
            WHERE d.id = :did
              AND ar.patient_id = :pid
              AND ar.request_status = 'approved'
              AND ar.records_sent = 1
            LIMIT 1
        ");
        $stmt->execute(['did' => $doctorId, 'pid' => $patientId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Resolve the medical facility name tied to the most recently
     * updated approved access request for this doctor/patient pair,
     * falling back to a default if none is set.
     */
    public function resolveFacilityName(int $doctorId, int $patientId, string $fallback = 'Central Medical Center'): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT medical_facility
                 FROM access_requests ar
                 JOIN doctors d ON d.name = ar.doctor_name
                 WHERE d.id = :did
                   AND ar.patient_id = :pid
                   AND ar.request_status = 'approved'
                   AND ar.records_sent = 1
                   AND ar.medical_facility IS NOT NULL
                   AND TRIM(ar.medical_facility) <> ''
                 ORDER BY ar.updated_at DESC, ar.id DESC
                 LIMIT 1"
            );
            $stmt->execute(['did' => $doctorId, 'pid' => $patientId]);
            $resolved = trim((string) $stmt->fetchColumn());
            return $resolved !== '' ? $resolved : $fallback;
        } catch (PDOException $e) {
            return $fallback;
        }
    }

    // ------------------------------------------------------------------
    // Patient detail / records / prescriptions / allergies (read)
    // ------------------------------------------------------------------

    public function getPatient(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, email, national_id, dob AS date_of_birth, gender, phone
             FROM patients WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$patientId]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        return $patient ?: null;
    }

    public function getMedicalRecords(int $patientId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id,
                   visit_date AS record_date,
                   visit_type,
                   COALESCE(diagnosis, clinical_notes) AS diagnosis,
                   treatment,
                   notes,
                   COALESCE(created_by, physician_name) AS doctor_name,
                   created_at
            FROM medical_records
            WHERE patient_id = ?
            ORDER BY visit_date DESC, created_at DESC
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Small preview version (used on update_records.php's recent-activity
     * panel) — limited to the 5 most recent entries.
     */
    public function getRecentMedicalRecords(int $patientId, int $limit = 5): array
    {
        $limit = max(1, (int) $limit);
        $stmt = $this->pdo->prepare("
            SELECT visit_type, hospital_name, visit_date, diagnosis, treatment
            FROM medical_records
            WHERE patient_id = :pid
            ORDER BY visit_date DESC, id DESC
            LIMIT $limit
        ");
        $stmt->execute(['pid' => $patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPrescriptions(int $patientId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT medication_name, dosage, frequency, duration, notes, prescribed_by, created_at
            FROM medication_prescriptions
            WHERE patient_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecentPrescriptions(int $patientId, int $limit = 5): array
    {
        $limit = max(1, (int) $limit);
        $stmt = $this->pdo->prepare("
            SELECT medication_name, dosage, frequency
            FROM medication_prescriptions
            WHERE patient_id = :pid
            ORDER BY id DESC
            LIMIT $limit
        ");
        $stmt->execute(['pid' => $patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllergies(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT allergen_name FROM patient_allergies
             WHERE patient_id = ? ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPrivacyConsents(int $patientId): array
    {
        $default = [
            'allergies_summary_text' => '',
            'chronic_diagnostic_logs_text' => '',
            'surgical_typologies_summary_text' => '',
            'surgical_typologies_necessary' => 0,
            'authored_by_doctor_name' => null,
            'updated_at' => null,
        ];

        $stmt = $this->pdo->prepare("
            SELECT allergies_summary_text, chronic_diagnostic_logs_text,
                   surgical_typologies_summary_text, surgical_typologies_necessary,
                   authored_by_doctor_name, updated_at
            FROM patient_privacy_consents
            WHERE patient_id = :pid
            LIMIT 1
        ");
        $stmt->execute(['pid' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: $default;
    }

    // ------------------------------------------------------------------
    // Writes: medical records, prescriptions, privacy consents
    // ------------------------------------------------------------------

    /**
     * Insert a new medical record for a patient. Caller is responsible
     * for verifying access first (verifyAccessById) and for validating
     * required fields.
     */
    public function addMedicalRecord(
        int $patientId,
        string $visitType,
        string $hospitalName,
        string $visitDate,
        string $notes,
        string $doctorName,
        string $diagnosis,
        string $treatment
    ): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO medical_records
                (patient_id, visit_type, hospital_name, visit_date, notes, created_by, diagnosis, treatment)
            VALUES
                (:pid, :vtype, :hospital, :vdate, :notes, :doc, :diag, :treat)
        ");
        return $stmt->execute([
            'pid'      => $patientId,
            'vtype'    => $visitType,
            'hospital' => $hospitalName,
            'vdate'    => $visitDate,
            'notes'    => $notes,
            'doc'      => $doctorName,
            'diag'     => $diagnosis,
            'treat'    => $treatment,
        ]);
    }

    /**
     * Insert a new prescription for a patient.
     */
    public function addPrescription(
        int $patientId,
        string $medicationName,
        string $dosage,
        string $frequency,
        string $duration,
        string $notes,
        string $doctorName
    ): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO medication_prescriptions
                (patient_id, medication_name, dosage, frequency, duration, notes, prescribed_by)
            VALUES
                (:pid, :mname, :dosage, :freq, :dur, :notes, :doc)
        ");
        return $stmt->execute([
            'pid'    => $patientId,
            'mname'  => $medicationName,
            'dosage' => $dosage,
            'freq'   => $frequency,
            'dur'    => $duration,
            'notes'  => $notes,
            'doc'    => $doctorName,
        ]);
    }

    /**
     * Insert or update (upsert) the privacy consent summaries a doctor
     * writes on behalf of a patient.
     */
    public function savePrivacyConsent(
        int $patientId,
        string $allergiesSummary,
        string $chronicDiagnosticLogs,
        string $surgicalTypologiesSummary,
        bool $surgicalTypologiesNecessary,
        int $doctorId,
        string $doctorName
    ): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO patient_privacy_consents
                (patient_id, allergies_summary_text, chronic_diagnostic_logs_text,
                 surgical_typologies_summary_text, surgical_typologies_necessary,
                 authored_by_doctor_id, authored_by_doctor_name)
            VALUES
                (:patient_id, :allergies_summary_text, :chronic_diagnostic_logs_text,
                 :surgical_typologies_summary_text, :surgical_typologies_necessary,
                 :doctor_id, :doctor_name)
            ON DUPLICATE KEY UPDATE
                allergies_summary_text = VALUES(allergies_summary_text),
                chronic_diagnostic_logs_text = VALUES(chronic_diagnostic_logs_text),
                surgical_typologies_summary_text = VALUES(surgical_typologies_summary_text),
                surgical_typologies_necessary = VALUES(surgical_typologies_necessary),
                authored_by_doctor_id = VALUES(authored_by_doctor_id),
                authored_by_doctor_name = VALUES(authored_by_doctor_name)
        ");
        return $stmt->execute([
            'patient_id' => $patientId,
            'allergies_summary_text' => $allergiesSummary,
            'chronic_diagnostic_logs_text' => $chronicDiagnosticLogs,
            'surgical_typologies_summary_text' => $surgicalTypologiesSummary,
            'surgical_typologies_necessary' => $surgicalTypologiesNecessary ? 1 : 0,
            'doctor_id' => $doctorId,
            'doctor_name' => $doctorName,
        ]);
    }
}