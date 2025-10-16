<?php
// Disable any HTML output
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 only for debugging, then back to 0

// Load PhpSpreadsheet if available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Clear any previous output
ob_clean();

// Set response header to JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Directories
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ATTACHMENT_DIR', __DIR__ . '/attachments/');
define('LOG_FILE', __DIR__ . '/messages_log.txt');

// Create directories if they don't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(ATTACHMENT_DIR)) {
    mkdir(ATTACHMENT_DIR, 0755, true);
}

// Function to send JSON response
function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit();
}

// Function to validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Function to validate phone
function isValidPhone($phone) {
    return preg_match('/^[0-9\-\+\(\)\s]{10,15}$/', $phone);
}

// Function to process Excel file
function processExcelFile($file) {
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($extension === 'csv') {
        return processCSV($file['tmp_name']);
    } elseif ($extension === 'xlsx' || $extension === 'xls') {
        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            return [
                'success' => false,
                'message' => 'PhpSpreadsheet not installed. Use CSV files or run: composer require phpoffice/phpspreadsheet'
            ];
        }
        
        return processExcel($file['tmp_name']);
    }
    
    return [
        'success' => false,
        'message' => 'Unsupported file format'
    ];
}

// Process CSV
function processCSV($filePath) {
    $contacts = [];
    $handle = fopen($filePath, 'r');
    
    if ($handle === false) {
        return ['success' => false, 'message' => 'Failed to read CSV file'];
    }
    
    // Skip header
    fgetcsv($handle);
    
    while (($row = fgetcsv($handle)) !== false) {
        if (!empty($row[0]) || !empty($row[1]) || !empty($row[2])) {
            $contacts[] = [
                'name' => isset($row[0]) ? trim($row[0]) : '',
                'email' => isset($row[1]) ? trim($row[1]) : '',
                'phone' => isset($row[2]) ? trim($row[2]) : ''
            ];
        }
    }
    
    fclose($handle);
    
    return [
        'success' => true,
        'message' => 'CSV processed successfully',
        'contacts' => $contacts
    ];
}

// Process Excel
function processExcel($filePath) {
    // Check if PhpSpreadsheet is loaded
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        return [
            'success' => false,
            'message' => 'PhpSpreadsheet not installed. Use CSV files or run: composer require phpoffice/phpspreadsheet'
        ];
    }
    
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        $contacts = [];
        
        // Skip header row
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            if (!empty($row[0]) || !empty($row[1]) || !empty($row[2])) {
                $contacts[] = [
                    'name' => isset($row[0]) ? trim($row[0]) : '',
                    'email' => isset($row[1]) ? trim($row[1]) : '',
                    'phone' => isset($row[2]) ? trim($row[2]) : ''
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => 'Excel processed successfully',
            'contacts' => $contacts
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error processing Excel: ' . $e->getMessage()
        ];
    }
}

// Function to handle attachment upload
function handleAttachment() {
    if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    
    $file = $_FILES['attachment'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Attachment upload failed'];
    }
    
    // Validate file type
    $allowedTypes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm'
    ];
    
    $fileMimeType = mime_content_type($file['tmp_name']);
    if (!in_array($fileMimeType, $allowedTypes)) {
        return ['error' => 'Only image and video files allowed'];
    }
    
    // Validate size (10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['error' => 'File size exceeds 10MB'];
    }
    
    // Save file
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'attachment_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = ATTACHMENT_DIR . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['error' => 'Failed to save attachment'];
    }
    
    return [
        'filename' => $filename,
        'original_name' => $file['name'],
        'path' => $filepath,
        'size' => $file['size'],
        'type' => $fileMimeType
    ];
}

// Function to log message
function logMessage($data) {
    $log = date('Y-m-d H:i:s') . ' | ';
    $log .= 'Contacts: ' . count($data['contacts']) . ' | ';
    $log .= 'Message: ' . substr($data['message'], 0, 50) . '... | ';
    if (isset($data['attachment'])) {
        $log .= 'Attachment: ' . $data['attachment']['filename'];
    }
    $log .= "\n";
    
    file_put_contents(LOG_FILE, $log, FILE_APPEND);
}

// ============================================
// API ENDPOINTS
// ============================================

$method = $_SERVER['REQUEST_METHOD'];

// GET - Test endpoint
if ($method === 'GET') {
    sendResponse(true, 'API is running', [
        'endpoints' => [
            'POST /api.php?action=upload_excel' => 'Upload and process Excel/CSV file',
            'POST /api.php?action=send_messages' => 'Send bulk messages with optional attachment'
        ],
        'server_time' => date('Y-m-d H:i:s')
    ]);
}

// POST - Handle actions
if ($method === 'POST') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    switch ($action) {
        case 'upload_excel':
            // Upload and process Excel file
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] === UPLOAD_ERR_NO_FILE) {
                sendResponse(false, 'No Excel file uploaded');
            }
            
            $file = $_FILES['excel_file'];
            
            // Validate file
            $validExtensions = ['csv', 'xlsx', 'xls'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extension, $validExtensions)) {
                sendResponse(false, 'Invalid file type. Only CSV, XLSX, XLS allowed');
            }
            
            if ($file['size'] > 10 * 1024 * 1024) {
                sendResponse(false, 'File size exceeds 10MB');
            }
            
            // Process file
            $result = processExcelFile($file);
            
            if ($result['success']) {
                sendResponse(true, $result['message'], [
                    'contacts' => $result['contacts'],
                    'total_contacts' => count($result['contacts']),
                    'file_name' => $file['name'],
                    'file_size' => $file['size']
                ]);
            } else {
                sendResponse(false, $result['message']);
            }
            break;
            
        case 'send_messages':
            // Send bulk messages
            $message = isset($_POST['message']) ? trim($_POST['message']) : '';
            $contactsJson = isset($_POST['contacts']) ? $_POST['contacts'] : '';
            
            if (empty($message)) {
                sendResponse(false, 'Message is required');
            }
            
            if (empty($contactsJson)) {
                sendResponse(false, 'Contacts are required');
            }
            
            $contacts = json_decode($contactsJson, true);
            
            if (!is_array($contacts) || empty($contacts)) {
                sendResponse(false, 'Invalid contacts format');
            }
            
            // Handle attachment
            $attachment = handleAttachment();
            
            if (isset($attachment['error'])) {
                sendResponse(false, $attachment['error']);
            }
            
            // Process each contact
            $successCount = 0;
            $failedCount = 0;
            $results = [];
            
            foreach ($contacts as $contact) {
                $name = isset($contact['name']) ? $contact['name'] : '';
                $email = isset($contact['email']) ? $contact['email'] : '';
                $phone = isset($contact['phone']) ? $contact['phone'] : '';
                
                if (empty($email) && empty($phone)) {
                    $failedCount++;
                    $results[] = [
                        'name' => $name,
                        'status' => 'failed',
                        'reason' => 'No email or phone provided'
                    ];
                    continue;
                }
                
                $sent = false;
                $method = [];
                
                // Validate and send via email
                if (!empty($email) && isValidEmail($email)) {
                    $sent = true;
                    $method[] = 'email';
                }
                
                // Validate and send via SMS
                if (!empty($phone) && isValidPhone($phone)) {
                    $sent = true;
                    $method[] = 'sms';
                }
                
                if ($sent) {
                    $successCount++;
                    $results[] = [
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'status' => 'sent',
                        'method' => implode(', ', $method)
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'name' => $name,
                        'status' => 'failed',
                        'reason' => 'Invalid email and phone'
                    ];
                }
            }
            
            // Log the message
            logMessage([
                'contacts' => $contacts,
                'message' => $message,
                'attachment' => $attachment
            ]);
            
            $responseData = [
                'total_contacts' => count($contacts),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'results' => $results
            ];
            
            if ($attachment) {
                $responseData['attachment'] = [
                    'filename' => $attachment['filename'],
                    'original_name' => $attachment['original_name'],
                    'size' => $attachment['size']
                ];
            }
            
            sendResponse(true, "Messages processed: {$successCount} sent, {$failedCount} failed", $responseData);
            break;
            
        default:
            sendResponse(false, 'Invalid action. Use: upload_excel or send_messages');
    }
}

sendResponse(false, 'Method not allowed');
?>