<?php
session_start();
require_once 'db.php';         // Links your central database configuration
require_once 'send-email.php'; // Links the PHPMailer engine connection handler

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name        = trim($_POST['full_name']);
    $email            = trim($_POST['email']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Additional form fields matching your database structure
    $dob              = $_POST['dob'];
    $gender           = $_POST['gender'];
    $blood_group      = $_POST['blood_group'];
    $phone            = trim($_POST['phone']);

    // Check if passwords match
    if ($password !== $confirm_password) {
        $message = "Passwords do not match!";
    } else {
        try {
            // 1. Check if the email address is already taken
            $stmt = $pdo->prepare("SELECT id FROM patients WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $message = "An account with this email address already exists.";
            } else {
                // 2. Hash password securely using industry-standard bcrypt
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                // 3. Generate verification data parameters
                $otp_code = (string)rand(100000, 999999);
                $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minute window

                // 4. Secure insertion using Prepared Statements
                $sql = "INSERT INTO patients (name, dob, gender, blood_group, phone, email, password, email_2fa_code, two_fa_expires_at) 
                        VALUES (:name, :dob, :gender, :blood_group, :phone, :email, :password, :otp, :expires)";
                
                $insertStmt = $pdo->prepare($sql);
                $insertStmt->execute([
                    ':name'        => $full_name,
                    ':dob'         => $dob,
                    ':gender'      => $gender,
                    ':blood_group' => $blood_group,
                    ':phone'       => $phone,
                    ':email'       => $email,
                    ':password'    => $hashed_password,
                    ':otp'         => $otp_code,
                    ':expires'     => $expires_at
                ]);

                // 5. Establish secure session environments
                $_SESSION['patient'] = $email;
                $_SESSION['patient_name'] = $full_name;
                $_SESSION['2fa_pending'] = true;

                // 6. Send the code straight to the patient's real email inbox
                sendOTP($email, $full_name, $otp_code);

                // Route directly to identity check step
                header("Location: verify-2fa.php");
                exit();
            }
        } catch (PDOException $e) {
            $message = "System registration error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patient Registration</title>
    <link rel="stylesheet" href="register.css">
</head>
<body>

<div class="container">

    <h2>Patient Registration</h2>

    <?php if (isset($message) && $message != "") { ?>
        <div class="message" style="color: red; text-align: center; margin-bottom: 15px; font-weight: bold;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php } ?>

    <form method="POST" action="register.php">

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" required>
        </div>

        <div class="form-group">
            <label>Date of Birth</label>
            <input type="date" name="dob" required>
        </div>

        <div class="form-group">
            <label>Gender</label>
            <select name="gender" required>
                <option value="">Select Gender</option>
                <option>Male</option>
                <option>Female</option>
            </select>
        </div>

        <div class="form-group">
            <label>Blood Group</label>
            <select name="blood_group" required>
                <option value="">Select Blood Group</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
            </select>
        </div>

        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" required>
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
        </div>

        <button type="submit" class="btn">
            Register
        </button>

    </form>

    <div class="login-link">
        Already have an account?
        <a href="login.php">Login Here</a>
    </div>

</div>

</body>
</html>