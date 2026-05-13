<?php
// Set a known password
$password = 'Admin@2025'; // Use a strong password of your choice

// Generate the hashed password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Output the hashed password
echo "Hashed Password: " . $hashed_password . "\n";

// Verify the password (for testing)
if (password_verify($password, $hashed_password)) {
    echo "Password is valid!\n";
} else {
    echo "Password is invalid!\n";
}
?>