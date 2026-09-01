<?php
// ============================================================
// register.php
// Member registration form -> inserts into the `members` table
// ============================================================

require "db_connect.php";

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 1. Collect form data
    $firstName  = trim($_POST["first_name"]);
    $lastName   = trim($_POST["last_name"]);
    $email      = trim($_POST["email"]);
    $phone      = trim($_POST["phone"]);
    $dob        = $_POST["date_of_birth"];
    $address    = trim($_POST["address"]);
    $password   = $_POST["password"];

    // 2. Basic validation
    if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
        $error = "Please fill out all required fields.";
    } else {

        // 3. Check if email already exists
        $checkStmt = $conn->prepare("SELECT MemberID FROM Members WHERE Email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $error = "That email is already registered.";
        } else {
            // 4. Hash the password -- NEVER store plain text passwords
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // 5. Insert into Members table
            $stmt = $conn->prepare(
                "INSERT INTO Members (FirstName, LastName, Email, PasswordHash, Phone, DateOfBirth, Address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("sssssss", $firstName, $lastName, $email, $passwordHash, $phone, $dob, $address);

            if ($stmt->execute()) {
                $success = "Registration successful! You may now log in.";
            } else {
                $error = "Something went wrong: " . $stmt->error;
            }
            $stmt->close();
        }
        $checkStmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Member Registration</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; justify-content: center; padding-top: 40px; }
    .card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); width: 350px; }
    h2 { text-align: center; margin-bottom: 20px; }
    label { display: block; margin-top: 12px; font-size: 14px; color: #333; }
    input { width: 100%; padding: 8px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    button { width: 100%; margin-top: 20px; padding: 10px; background: #2d6cdf; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #1e54b7; }
    .msg-success { color: green; margin-top: 15px; text-align: center; }
    .msg-error { color: red; margin-top: 15px; text-align: center; }
</style>
</head>
<body>
<div class="card">
    <h2>Member Registration</h2>

    <?php if ($success): ?>
        <p class="msg-success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="msg-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <label>First Name</label>
        <input type="text" name="first_name" required>

        <label>Last Name</label>
        <input type="text" name="last_name" required>

        <label>Email</label>
        <input type="email" name="email" required>

        <label>Phone</label>
        <input type="text" name="phone">

        <label>Date of Birth</label>
        <input type="date" name="date_of_birth">

        <label>Address</label>
        <input type="text" name="address">

        <label>Password</label>
        <input type="password" name="password" required>

        <button type="submit">Register</button>
    </form>
</div>
</body>
</html>
