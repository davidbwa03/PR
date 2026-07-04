<?php

/**
 * AuthService
 *
 * Contains the pure authentication logic that used to live inline
 * inside login.php. Pulling it out means it can be unit tested with
 * PHPUnit without needing a real HTTP request, a real session,
 * or a real database connection (a mock PDO is enough).
 */
class AuthService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Attempt to log an administrator in.
     *
     * @param string $email
     * @param string $password
     * @return array|null  The admin row (without password) on success, null on failure.
     */
    public function attemptLogin(string $email, string $password): ?array
    {
        $email = trim($email);

        if ($email === '' || $password === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT admin_id, full_name, email, password FROM administrators WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            // Don't leak the password hash back to callers.
            unset($admin['password']);
            return $admin;
        }

        return null;
    }
}
