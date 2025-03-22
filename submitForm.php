<?php
// Disable error display (log them instead in production)
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering to catch any stray output
ob_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $name    = isset($_POST['w3lName']) ? strip_tags(trim($_POST['w3lName'])) : '';
    $email   = isset($_POST['w3lSender']) ? filter_var(trim($_POST['w3lSender']), FILTER_SANITIZE_EMAIL) : '';
    $subject = isset($_POST['w3lSubject']) ? strip_tags(trim($_POST['w3lSubject'])) : '';
    $type    = isset($_POST['w3lType']) ? strip_tags(trim($_POST['w3lType'])) : '';
    $message = isset($_POST['w3lMessage']) ? strip_tags(trim($_POST['w3lMessage'])) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
        exit;
    }
    
    // Prepare the email content
    $to = 'ameen.adeeb@gmail.com'; // Replace with your destination email address
    $fullSubject = "New Contact Form Submission: " . $subject;
    $emailContent = "Name: $name\n";
    $emailContent .= "Email: $email\n";
    $emailContent .= "Subject: $subject\n";
    $emailContent .= "Type: $type\n";
    $emailContent .= "Message: $message\n";
    $headers = "From: $name <$email>";

    // Send the email
    if (mail($to, $fullSubject, $emailContent, $headers)) {
        echo json_encode(['status' => 'success', 'message' => 'Thank you! Your message has been sent.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Oops! Something went wrong. Please try again later.']);
    }
    exit;
}

// Clear any unexpected output
ob_clean();

echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
ob_end_flush();
?>
