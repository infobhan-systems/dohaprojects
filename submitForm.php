<?php
// Disable error display (log them instead in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('error_log', 'error.log'); // Will log to the same directory as the script

// Start output buffering to catch any stray output
ob_start();
header('Content-Type: application/json');

// Load AWS credentials from a file located OUTSIDE the web root directory
// Assuming your web root is something like /home/username/public_html/
// And this file is placed at /home/username/aws_config.php
$credentialsFile = 'aws_config.php';

if (!file_exists($credentialsFile)) {
    error_log("AWS credentials file not found: $credentialsFile");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error.']);
    exit;
}

require_once($credentialsFile);

// Check if credentials are available
if (!defined('AWS_REGION') || !defined('AWS_ACCESS_KEY') || !defined('AWS_SECRET_KEY') || 
    !defined('SES_SENDER_EMAIL') || !defined('SES_RECIPIENT_EMAIL')) {
    error_log("AWS credentials not properly defined in config file");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error.']);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    // Sanitize and validate input
    $name    = isset($_POST['w3lName']) ? strip_tags(trim($_POST['w3lName'])) : '';
    $email   = isset($_POST['w3lSender']) ? filter_var(trim($_POST['w3lSender']), FILTER_SANITIZE_EMAIL) : '';
    $subject = isset($_POST['w3lSubject']) ? strip_tags(trim($_POST['w3lSubject'])) : '';
    $type    = isset($_POST['w3lType']) ? strip_tags(trim($_POST['w3lType'])) : '';
    $message = isset($_POST['w3lMessage']) ? strip_tags(trim($_POST['w3lMessage'])) : '';

    // Validate required fields
    if (empty($name) || empty($email) || empty($message)) {
        throw new Exception('Please fill all required fields.');
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address.');
    }
    
    // Prepare email subject
    $fullSubject = "New Contact Form Submission: " . $subject;
    
    // Prepare HTML email content
    $htmlEmailContent = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Contact Form Submission</title>
        <style>
            body {
                font-family: "Sarabun", sans-serif;
                line-height: 1.5;
                color: #444;
                margin: 0;
                padding: 0;
                background-color: #f9f9f9;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                background-color: #ffffff;
                border-radius: 8px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
            .header {
                background-color: #0d3661;
                color: #fff;
                padding: 20px;
                text-align: center;
                border-top-left-radius: 8px;
                border-top-right-radius: 8px;
            }
            .header h1 {
                font-size: 24px;
                margin: 0;
                font-weight: 700;
            }
            .content {
                padding: 20px;
            }
            .info-item {
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 1px solid #eee;
            }
            .info-item:last-child {
                border-bottom: none;
            }
            .label {
                font-weight: 600;
                color: #0d3661;
                display: block;
                margin-bottom: 5px;
            }
            .value {
                color: #444;
            }
            .message-box {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-top: 10px;
                white-space: pre-wrap;
            }
            .footer {
                text-align: center;
                padding: 20px;
                font-size: 12px;
                color: #777;
                background-color: #f1f1f1;
                border-bottom-left-radius: 8px;
                border-bottom-right-radius: 8px;
            }
            .button {
                display: inline-block;
                background-color: #ff6600;
                color: white;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 15px;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>New Contact Form Submission</h1>
            </div>
            <div class="content">
                <div class="info-item">
                    <span class="label">Name:</span>
                    <span class="value">' . htmlspecialchars($name) . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Email:</span>
                    <span class="value">' . htmlspecialchars($email) . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Subject:</span>
                    <span class="value">' . htmlspecialchars($subject) . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Type:</span>
                    <span class="value">' . htmlspecialchars($type) . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Message:</span>
                    <div class="message-box">' . nl2br(htmlspecialchars($message)) . '</div>
                </div>
                <div style="text-align: center;">
                    <a href="mailto:' . htmlspecialchars($email) . '" class="button">Reply to ' . htmlspecialchars($name) . '</a>
                </div>
            </div>
            <div class="footer">
                This email was sent from your website contact form. &copy; ' . date('Y') . ' Doha Projects Trading & Services, www.dohaprojects.com .
            </div>
        </div>
    </body>
    </html>';
    
    // Plain text version for email clients that don't support HTML
    $textEmailContent = "Name: $name\n";
    $textEmailContent .= "Email: $email\n";
    $textEmailContent .= "Subject: $subject\n";
    $textEmailContent .= "Type: $type\n";
    $textEmailContent .= "Message: $message\n";
    
    // Generate a random boundary for the multipart message
    $boundary = md5(time());
    
    // Construct the request
    $date = gmdate('D, d M Y H:i:s e');
    $host = 'email.' . AWS_REGION . '.amazonaws.com';
    $endpoint = 'https://' . $host;
    
    // Prepare the request payload
    $actionName = 'SendEmail';
    $payload = [
        'Action' => $actionName,
        'Version' => '2010-12-01',
        'Source' => SES_SENDER_EMAIL,
        'Destination.ToAddresses.member.1' => SES_RECIPIENT_EMAIL,
        'Message.Subject.Data' => $fullSubject,
        'Message.Subject.Charset' => 'UTF-8',
        'Message.Body.Text.Data' => $textEmailContent,
        'Message.Body.Text.Charset' => 'UTF-8',
        'Message.Body.Html.Data' => $htmlEmailContent,
        'Message.Body.Html.Charset' => 'UTF-8',
        'ReplyToAddresses.member.1' => $email
    ];
    
    // Create the query string
    $queryString = http_build_query($payload);
    
    // Create the signature
    $date = gmdate('Ymd\THis\Z');
    $algorithm = 'AWS4-HMAC-SHA256';
    $credential_scope = gmdate('Ymd') . '/' . AWS_REGION . '/ses/aws4_request';
    $signed_headers = 'host;x-amz-date';
    
    // Step 1: Create canonical request
    $canonical_request = "POST\n/\n\nhost:" . $host . "\nx-amz-date:" . $date . "\n\n" . $signed_headers . "\n" . hash('sha256', $queryString);
    
    // Step 2: Create string to sign
    $string_to_sign = $algorithm . "\n" . $date . "\n" . $credential_scope . "\n" . hash('sha256', $canonical_request);
    
    // Step 3: Calculate signature
    $kDate = hash_hmac('sha256', gmdate('Ymd'), 'AWS4' . AWS_SECRET_KEY, true);
    $kRegion = hash_hmac('sha256', AWS_REGION, $kDate, true);
    $kService = hash_hmac('sha256', 'ses', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $string_to_sign, $kSigning);
    
    // Step 4: Create authorization header
    $authorization = $algorithm . ' ' . 
                    'Credential=' . AWS_ACCESS_KEY . '/' . $credential_scope . ', ' .
                    'SignedHeaders=' . $signed_headers . ', ' .
                    'Signature=' . $signature;
    
    // Prepare the cURL request
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Host: ' . $host,
        'X-Amz-Date: ' . $date,
        'Authorization: ' . $authorization
    ]);

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Check for errors
    if ($curlError) {
        throw new Exception("cURL Error: $curlError");
    }
    
    if ($httpCode !== 200) {
        error_log("AWS SES Error Response: $response");
        if (strpos($response, '<Code>') !== false) {
            preg_match('/<Code>(.*?)<\/Code>/', $response, $errorCode);
            preg_match('/<Message>(.*?)<\/Message>/', $response, $errorMessage);
            $awsErrorCode = isset($errorCode[1]) ? $errorCode[1] : 'Unknown';
            $awsErrorMessage = isset($errorMessage[1]) ? $errorMessage[1] : 'Unknown error';
            error_log("AWS Error Code: $awsErrorCode, Message: $awsErrorMessage");
        }
        throw new Exception('Failed to send email. HTTP Code: ' . $httpCode);
    }
    
    // Parse message ID if needed
    preg_match('/<MessageId>(.*?)<\/MessageId>/', $response, $messageId);
    $emailMessageId = isset($messageId[1]) ? $messageId[1] : '';
    
    // Return success response
    ob_clean();
    echo json_encode([
        'status' => 'success', 
        'message' => 'Thank you! Your message has been sent.',
        'messageId' => $emailMessageId
    ]);
    exit;
    
} catch (Exception $e) {
    // Log errors
    error_log('Contact Form Error: ' . $e->getMessage());
    
    // Return error response
    ob_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'Oops! Something went wrong. Please try again later.'
    ]);
    exit;
}

// Clear any unexpected output
ob_clean();
echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
ob_end_flush();
?>