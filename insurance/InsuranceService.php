<?php

/**
 * InsuranceService
 *
 * Centralizes the claim-review logic that previously lived inline in
 * insurance/data_requests.php:
 *   - detecting which column names this install's `claims` table uses
 *     for the reviewed-date and rejection-reason fields (schemas vary
 *     slightly across deployments)
 *   - fetching pending claims awaiting a decision
 *   - fetching recently reviewed claims
 *   - approving or declining a specific claim, with the same guards
 *     the original page enforced (valid action, decline reason required,
 *     claim must exist, claim must still be pending)
 *
 * Usage:
 *   require_once 'db.php';              // provides $pdo
 *   require_once 'InsuranceService.php';
 *   $insuranceService = new InsuranceService($pdo);
 *   $pending = $insuranceService->getPendingClaims();
 */
class InsuranceService
{
    private PDO $pdo;

    /** @var string Column used for "when was this claim reviewed" */
    private string $reviewedDateColumn = 'reviewed_date';

    /** @var string Column used for "why was this claim declined" */
    private string $rejectionReasonColumn = 'rejection_reason';

    /** @var array<string,string> action => resulting status label */
    private const ALLOWED_ACTIONS = [
        'approve' => 'Approved',
        'decline' => 'Rejected',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->detectSchema();
    }

    /**
     * Detect which optional column names this deployment's `claims`
     * table actually uses. Falls back to the defaults on any failure
     * (e.g. table doesn't exist yet, or SHOW COLUMNS isn't permitted).
     */
    private function detectSchema(): void
    {
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM claims")->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('reviewed_at', $columns, true)) {
                $this->reviewedDateColumn = 'reviewed_at';
            }
            if (in_array('decline_reason', $columns, true)) {
                $this->rejectionReasonColumn = 'decline_reason';
            }
        } catch (PDOException $e) {
            // Keep default schema column names when metadata lookup fails.
        }
    }

    public function getReviewedDateColumn(): string
    {
        return $this->reviewedDateColumn;
    }

    public function getRejectionReasonColumn(): string
    {
        return $this->rejectionReasonColumn;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * All claims currently awaiting a decision, oldest first.
     */
    public function getPendingClaims(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT c.claim_id, c.claim_number, c.claim_amount, c.claim_reason, c.status, c.submitted_date,
                        p.id AS patient_id, p.name AS patient_name, p.national_id,
                        h.id AS hospital_id, h.name AS hospital_name
                   FROM claims c
                   LEFT JOIN patients p ON p.id = c.patient_id
                   LEFT JOIN hospitals h ON h.id = c.hospital_id
                    WHERE LOWER(TRIM(c.status)) = 'pending'
                  ORDER BY c.submitted_date ASC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Pending claims fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Most recently reviewed (approved/declined) claims, newest first.
     */
    public function getReviewedClaims(int $limit = 8): array
    {
        $limit = max(1, $limit);
        $reviewedCol = $this->reviewedDateColumn;
        $reasonCol = $this->rejectionReasonColumn;

        try {
            $stmt = $this->pdo->query(
                "SELECT c.claim_id, c.claim_number, c.claim_amount, c.claim_reason, c.status, c.submitted_date,
                        c.`{$reasonCol}` AS rejection_reason, c.`{$reviewedCol}` AS reviewed_at,
                        p.name AS patient_name,
                        h.name AS hospital_name
                   FROM claims c
                   LEFT JOIN patients p ON p.id = c.patient_id
                   LEFT JOIN hospitals h ON h.id = c.hospital_id
                    WHERE LOWER(TRIM(c.status)) IN ('approved', 'rejected', 'declined')
                    ORDER BY c.`{$reviewedCol}` DESC
                  LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Reviewed claims fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch a single claim's id/status/number — used internally before
     * approving or declining, but exposed since callers may want it too.
     */
    public function getClaimSummary(int $claimId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT claim_id, status, claim_number FROM claims WHERE claim_id = ? LIMIT 1");
        $stmt->execute([$claimId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Approve or decline a claim.
     *
     * @return array{success: bool, message: string}
     */
    public function reviewClaim(int $claimId, string $action, int $insurerId, string $declineReason = ''): array
    {
        $declineReason = trim($declineReason);

        if ($claimId <= 0 || !isset(self::ALLOWED_ACTIONS[$action])) {
            return ['success' => false, 'message' => 'Invalid claim action.'];
        }

        if ($action === 'decline' && $declineReason === '') {
            return ['success' => false, 'message' => 'Please provide a reason for declining this claim.'];
        }

        try {
            $claim = $this->getClaimSummary($claimId);

            if (!$claim) {
                return ['success' => false, 'message' => 'Claim not found.'];
            }

            if (strtolower(trim((string) $claim['status'])) !== 'pending') {
                return ['success' => false, 'message' => 'This claim has already been reviewed.'];
            }

            $newStatus = self::ALLOWED_ACTIONS[$action];
            $reviewedCol = $this->reviewedDateColumn;
            $reasonCol = $this->rejectionReasonColumn;

            if ($action === 'decline') {
                $stmt = $this->pdo->prepare(
                    "UPDATE claims
                        SET status = ?, `{$reasonCol}` = ?, reviewed_by = ?, `{$reviewedCol}` = NOW(), updated_at = NOW()
                      WHERE claim_id = ?"
                );
                $stmt->execute([$newStatus, $declineReason, $insurerId, $claimId]);
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE claims
                        SET status = ?, reviewed_by = ?, `{$reviewedCol}` = NOW(), updated_at = NOW()
                      WHERE claim_id = ?"
                );
                $stmt->execute([$newStatus, $insurerId, $claimId]);
            }

            $claimLabel = $claim['claim_number'] ?? ('Claim #' . $claimId);

            return [
                'success' => true,
                'message' => "Claim {$claimLabel} has been " . strtolower($newStatus) . '.',
            ];
        } catch (PDOException $e) {
            error_log('Claim review failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not update the claim. Please try again.'];
        }
    }
}