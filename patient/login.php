<?php

session_start();

if(isset($_POST['login'])){


$email = $_POST['email'];
$password = $_POST['password'];


/*
Later:
Search email from database
Verify password
*/


// Temporary testing

if($email=="patient@gmail.com" && $password=="12345"){

    $_SESSION['patient']=$email;

    header("Location: dashboard.php");

}
else{

    $error="Invalid email or password";

}


}

?>


<!DOCTYPE html>
<html>

<head>

<title>Patient Login</title>

<link rel="stylesheet" href="login.css">

</head>


<body>


<div class="container">


<div class="logo">
<a href="../index.html">🏥</a>
</div>


<h1>
Patient Login
</h1>



<?php

if(isset($error)){
echo "<p class='error'>$error</p>";
}

?>


<form method="POST">


<input 
type="email"
name="email"
placeholder="Email"
required>



<input
type="password"
name="password"
placeholder="Password"
required>



<button name="login">
Login
</button>



<p>
Don't have an account?

<a href="register.php">
Register
</a>

</p>


</form>



</div>


</body>
</html>