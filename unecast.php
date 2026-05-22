<?php
/**
 * ---------------------------------------------------------------
 *  Unecast Two-Factor Authentication (2FA) Module
 * ---------------------------------------------------------------
 * Company    : Webbyus Technologies (Private) Limited
 * Developer  : Sameera Dananjaya Wijerathna
 * Description: Provides secure 2FA (SMS/Email) integration for WHMCS
 * Version    : 1.3.0
 * Last Update: 22/05/2026
 * ---------------------------------------------------------------
 */

if (!defined("WHMCS")) {
    // WHMCS loads modules internally, but keep the required files for direct module context.
}

require_once __DIR__ . "/../../../init.php";
require_once __DIR__ . "/../../../configuration.php";

use Illuminate\Database\Capsule\Manager as Capsule;

// ---------------------------------------------------------------
// MODULE CONFIG
// ---------------------------------------------------------------
function unecast_config()
{
    $balanceArr      = getUnecastBalance();
    $balance         = is_array($balanceArr) ? ($balanceArr['balance'] ?? 0) : 0;
    $balanceCurrency = is_array($balanceArr) ? ($balanceArr['currency'] ?? '') : '';

    if ((float)$balance < 1) {
        $balanceText = "<strong style='color:red;'>Unecast SMS Balance: {$balance} {$balanceCurrency}</strong>";
    } else {
        $balanceText = "<strong style='color:green;'>Unecast SMS Balance: {$balance} {$balanceCurrency}</strong>";
    }

    return [
        "FriendlyName" => [
            "Type"  => "System",
            "Value" => "Unecast",
        ],
        "Description" => [
            "Type"  => "System",
            "Value" => $balanceText,
        ],
        "ShortDescription" => [
            "Type"  => "System",
            "Value" => "Secure 2FA with SMS/Email via Unecast",
        ],
        "api_key" => [
            "FriendlyName" => "API Key",
            "Type"         => "textarea",
            "Rows"         => 2,
            "Description"  => "Purchase the API key from Unecast to enable integration.",
        ],
        "disable_sms" => [
            "FriendlyName" => "Disable SMS",
            "Type"         => "yesno",
            "Description"  => "Tick to disable SMS as a 2FA method.",
        ],
        "disable_email" => [
            "FriendlyName" => "Disable Email",
            "Type"         => "yesno",
            "Description"  => "Tick to disable Email as a 2FA method.",
        ],
    ];
}

// ---------------------------------------------------------------
// CHALLENGE - Send OTP
// ---------------------------------------------------------------
function unecast_challenge($params)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $apiKey       = trim((string)($params["settings"]["api_key"] ?? ''));
    $disableSms   = !empty($params["settings"]["disable_sms"]);
    $disableEmail = !empty($params["settings"]["disable_email"]);

    try {
        $code = (string)random_int(100000, 999999);
    } catch (\Throwable $e) {
        $code = (string)mt_rand(100000, 999999);
    }

    $_SESSION["smsCode"] = $code;

    $context  = resolveUnecastLoginContext($params);
    $isAdmin  = $context['type'] === 'admin';
    $adminId  = (int)$context['admin_id'];
    $clientId = (int)$context['client_id'];

    logActivity(
        "Unecast Context: " . strtoupper($context['type']) .
        " | adminId={$adminId} | clientId={$clientId} | source=" . ($context['source'] ?? 'unknown')
    );

    logActivity(
        "Unecast Session: 2faadminid=" . ($_SESSION['2faadminid'] ?? 'NULL') .
        " | adminid=" . ($_SESSION['adminid'] ?? 'NULL') .
        " | uid=" . ($_SESSION['uid'] ?? 'NULL') .
        " | 2faclientid=" . ($_SESSION['2faclientid'] ?? 'NULL')
    );

    // -------------------------
    // ADMIN FLOW
    // -------------------------
    if ($isAdmin && $adminId > 0) {
        $adminInfo     = getAdminInfo($adminId);
        $adminUsername = $adminInfo->username ?? '';
        $adminPhone    = $disableSms ? null : getAdminMobile($adminId);

        logActivity("Unecast Admin Id: {$adminId} | Username: {$adminUsername} | Phone: " . json_encode($adminPhone));

        if (!$disableEmail) {
            $emailStatus = sendAuthCodeEmailToAdmin($adminId, $adminUsername, $code);
            logActivity("Unecast Admin Email Status: " . ($emailStatus ? "success" : "failed"));
        }

        if (!$disableSms) {
            if (!empty($adminPhone)) {
                $smsStatus = sendAuthCodeSms($apiKey, $adminPhone, $code);
                logActivity("Unecast Admin SMS Status: " . ($smsStatus ? "success" : "failed"));
            } else {
                logActivity("Unecast Admin SMS skipped: admin mobile is empty.");
            }
        }

        return buildHtmlForm();
    }

    // -------------------------
    // CLIENT FLOW
    // -------------------------
    $clientPhone = null;

    if ($clientId > 0) {
        $clientPhone = getClientMobile($clientId);
    } else {
        $emailAddress = $params["user_info"]["email"] ?? null;
        $clientInfo   = $emailAddress ? getClientByEmail($emailAddress) : null;

        if (is_array($clientInfo)) {
            $clientId    = (int)($clientInfo['client_id'] ?? 0);
            $clientPhone = normalizeSmsPhone('', $clientInfo['phonenumber'] ?? '');
        }
    }

    logActivity("Unecast Client Id: {$clientId} | Phone: " . json_encode($clientPhone));

    if ($clientId > 0) {
        if (!$disableEmail) {
            $emailStatus = sendAuthCodeEmailToClient($clientId, $code);
            logActivity("Unecast Client Email Status: " . ($emailStatus ? "success" : "failed"));
        }

        if (!$disableSms) {
            if (!empty($clientPhone)) {
                $smsStatus = sendAuthCodeSms($apiKey, $clientPhone, $code);
                logActivity("Unecast Client SMS Status: " . ($smsStatus ? "success" : "failed"));
            } else {
                logActivity("Unecast Client SMS skipped: client mobile is empty.");
            }
        }
    } else {
        logActivity("Unecast Client flow skipped: unable to resolve valid client ID.");
    }

    return buildHtmlForm();
}

// ---------------------------------------------------------------
// VERIFY OTP
// ---------------------------------------------------------------
function unecast_verify($params)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userCode  = isset($_POST["code"]) ? trim((string)$_POST["code"]) : '';
    $validCode = isset($_SESSION["smsCode"]) ? trim((string)$_SESSION["smsCode"]) : '';

    if ($userCode !== '' && $validCode !== '' && hash_equals($validCode, $userCode)) {
        unset($_SESSION["smsCode"]);
        return true;
    }

    return false;
}

// ---------------------------------------------------------------
// ACTIVATE - Show mobile number form in 2FA setup screen
// ---------------------------------------------------------------
function unecast_activate($params)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminId = resolveAdminIdForSetup($params);
    $current = $adminId ? (getAdminMobile($adminId) ?? '') : '';

    $currentDisplay = $current
        ? "<p class='text-success'>Current: <strong>" . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . "</strong></p>"
        : "<p class='text-muted'>No mobile number saved yet.</p>";

    return '
        <div>
            ' . $currentDisplay . '
            <form method="post" action="">
                <div class="form-group">
                    <label>Mobile Number</label>
                    <input type="text" name="admin_mobile" value="' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '"
                           placeholder="Eg: 94771234567" class="form-control" required>
                    <small class="text-muted">Include country code where possible. Example: 94771234567</small>
                </div>
                <br>
                <input type="submit" value="Save &amp; Enable 2FA" class="btn btn-primary">
            </form>
        </div>
    ';
}

// ---------------------------------------------------------------
// ACTIVATE VERIFY - Save mobile number
// ---------------------------------------------------------------
function unecast_activateverify($params)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminId     = resolveAdminIdForSetup($params);
    $adminMobile = (string)($params['post_vars']['admin_mobile'] ?? '');

    if ($adminId <= 0) {
        logActivity("Unecast Activate Verify failed: unable to resolve admin ID.");
        return ['success' => false, 'error' => 'Unable to detect admin user'];
    }

    if (trim($adminMobile) === '') {
        return ['success' => false, 'error' => 'Mobile number is required'];
    }

    $savedMobile = saveAdminMobile($adminId, $adminMobile);

    if (!$savedMobile) {
        return ['success' => false, 'error' => 'Invalid mobile number'];
    }

    return [
        'success'  => true,
        'settings' => ['admin_mobile' => $savedMobile],
    ];
}

// ---------------------------------------------------------------
// DEACTIVATE - Clear mobile from user_preferences
// ---------------------------------------------------------------
function unecast_deactivate($params)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminId = resolveAdminIdForSetup($params);

    if (!$adminId) {
        logActivity('Unecast: deactivate - no admin id');
        return true;
    }

    try {
        $row  = Capsule::table('tbladmins')->where('id', $adminId)->first(['user_preferences']);
        $data = ($row && $row->user_preferences) ? json_decode($row->user_preferences, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        unset($data['unecast_mobile'], $data['admin_mobile']);

        Capsule::table('tbladmins')
            ->where('id', $adminId)
            ->update(['user_preferences' => json_encode($data, JSON_UNESCAPED_SLASHES)]);

        logActivity("Unecast: admin {$adminId} mobile cleared on deactivation");
    } catch (\Throwable $e) {
        logActivity("Unecast Deactivate Error: " . $e->getMessage());
    }

    return true;
}

// ---------------------------------------------------------------
// CONTEXT RESOLUTION
// ---------------------------------------------------------------
function resolveUnecastLoginContext($params): array
{
    $adminId  = 0;
    $clientId = 0;
    $source   = 'none';

    // Admin sessions are the strongest signal.
    if (!empty($_SESSION['2faadminid'])) {
        $adminId = (int)$_SESSION['2faadminid'];
        $source  = 'session_2faadminid';
    } elseif (!empty($_SESSION['adminid'])) {
        $adminId = (int)$_SESSION['adminid'];
        $source  = 'session_adminid';
    }

    if ($adminId > 0) {
        return [
            'type'      => 'admin',
            'admin_id'  => $adminId,
            'client_id' => 0,
            'source'    => $source,
        ];
    }

    // Client sessions are the next strongest signal.
    if (!empty($_SESSION['2faclientid'])) {
        $clientId = resolveClientIdFromPossibleId((int)$_SESSION['2faclientid']);
        $source   = 'session_2faclientid';
    } elseif (!empty($_SESSION['uid'])) {
        $clientId = resolveClientIdFromPossibleId((int)$_SESSION['uid']);
        $source   = 'session_uid';
    }

    if ($clientId > 0) {
        return [
            'type'      => 'client',
            'admin_id'  => 0,
            'client_id' => $clientId,
            'source'    => $source,
        ];
    }

    // Fallback to user_info. For WHMCS client users, user_info[id] is often tblusers.id, not tblclients.id.
    $possibleId = (int)($params['user_info']['id'] ?? 0);

    if ($possibleId > 0) {
        $mappedClientId = getClientIdFromUserId($possibleId);

        if ($mappedClientId > 0) {
            return [
                'type'      => 'client',
                'admin_id'  => 0,
                'client_id' => $mappedClientId,
                'source'    => 'params_user_info_id_to_tblusers_clients',
            ];
        }

        // Only treat as admin after failing client-user mapping.
        if (isUnecastAdminId($possibleId)) {
            return [
                'type'      => 'admin',
                'admin_id'  => $possibleId,
                'client_id' => 0,
                'source'    => 'params_user_info_id_tbladmins',
            ];
        }

        // Final fallback: maybe it is already tblclients.id.
        if (isUnecastClientId($possibleId)) {
            return [
                'type'      => 'client',
                'admin_id'  => 0,
                'client_id' => $possibleId,
                'source'    => 'params_user_info_id_tblclients',
            ];
        }
    }

    return [
        'type'      => 'client',
        'admin_id'  => 0,
        'client_id' => 0,
        'source'    => 'unresolved',
    ];
}

function resolveAdminIdForSetup($params): int
{
    $possibleIds = [
        $_SESSION['2faadminid'] ?? null,
        $_SESSION['adminid'] ?? null,
        $params['user_info']['id'] ?? null,
    ];

    foreach ($possibleIds as $id) {
        $id = (int)$id;
        if ($id > 0 && isUnecastAdminId($id)) {
            return $id;
        }
    }

    return 0;
}

function resolveClientIdFromPossibleId($possibleId): int
{
    $possibleId = (int)$possibleId;

    if ($possibleId <= 0) {
        return 0;
    }

    // In classic WHMCS sessions, uid is commonly tblclients.id.
    if (isUnecastClientId($possibleId)) {
        return $possibleId;
    }

    // In newer WHMCS user system, user_info[id] is commonly tblusers.id.
    $clientId = getClientIdFromUserId($possibleId);
    if ($clientId > 0) {
        return $clientId;
    }

    return 0;
}

// ---------------------------------------------------------------
// ADMIN MOBILE STORAGE
// ---------------------------------------------------------------
function saveAdminMobile(int $adminId, string $rawPhone): ?string
{
    $digits = normalizeSmsPhone('', $rawPhone);

    if ($digits === '') {
        logActivity("Unecast: admin {$adminId} mobile save failed - invalid phone.");
        return null;
    }

    try {
        $row  = Capsule::table('tbladmins')->where('id', $adminId)->first(['user_preferences']);
        $data = ($row && $row->user_preferences) ? json_decode($row->user_preferences, true) : [];

        if (!is_array($data)) {
            $data = [];
        }

        // Save both keys for backward compatibility.
        $data['unecast_mobile'] = $digits;
        $data['admin_mobile']   = $digits;

        Capsule::table('tbladmins')->where('id', $adminId)->update([
            'user_preferences' => json_encode($data, JSON_UNESCAPED_SLASHES),
        ]);

        logActivity("Unecast: admin {$adminId} mobile saved to user_preferences: {$digits}");

        return $digits;
    } catch (\Throwable $e) {
        logActivity("Unecast Save Admin Mobile Error: " . $e->getMessage());
        return null;
    }
}

function getAdminMobile(?int $adminId): ?string
{
    $adminId = (int)$adminId;

    if ($adminId <= 0) {
        return null;
    }

    try {
        $row = Capsule::table('tbladmins')
            ->where('id', $adminId)
            ->first(['user_preferences']);

        if (!$row || empty($row->user_preferences)) {
            return null;
        }

        $data = json_decode($row->user_preferences, true);

        if (!is_array($data)) {
            return null;
        }

        $mobile = $data['unecast_mobile'] ?? $data['admin_mobile'] ?? null;

        return $mobile ? normalizeSmsPhone('', $mobile) : null;
    } catch (\Throwable $e) {
        logActivity("Unecast Get Admin Mobile Error: " . $e->getMessage());
        return null;
    }
}

// ---------------------------------------------------------------
// GENERAL HELPERS
// ---------------------------------------------------------------
function getAdminInfo($adminId)
{
    try {
        return Capsule::table('tbladmins')
            ->where('id', (int)$adminId)
            ->first(['firstname', 'lastname', 'email', 'username']);
    } catch (\Throwable $e) {
        logActivity("Unecast Get Admin Info Error: " . $e->getMessage());
        return null;
    }
}

function isUnecastAdminId($id): bool
{
    $id = (int)$id;

    if ($id <= 0) {
        return false;
    }

    try {
        return Capsule::table('tbladmins')
            ->where('id', $id)
            ->exists();
    } catch (\Throwable $e) {
        logActivity("Unecast Admin Lookup Error: " . $e->getMessage());
        return false;
    }
}

function isUnecastClientId($id): bool
{
    $id = (int)$id;

    if ($id <= 0) {
        return false;
    }

    try {
        return Capsule::table('tblclients')
            ->where('id', $id)
            ->exists();
    } catch (\Throwable $e) {
        logActivity("Unecast Client Lookup Error: " . $e->getMessage());
        return false;
    }
}

function getClientIdFromUserId($userId): int
{
    $userId = (int)$userId;

    if ($userId <= 0) {
        return 0;
    }

    try {
        $relation = Capsule::table('tblusers_clients')
            ->where('auth_user_id', $userId)
            ->orderBy('owner', 'desc')
            ->first(['client_id']);

        return $relation ? (int)$relation->client_id : 0;
    } catch (\Throwable $e) {
        logActivity("Unecast User to Client Lookup Error: " . $e->getMessage());
        return 0;
    }
}

function getClientMobile($clientId): ?string
{
    $clientId = (int)$clientId;

    if ($clientId <= 0) {
        return null;
    }

    try {
        // Avoid selecting phonecc because not every WHMCS install has that column.
        $client = Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['id', 'phonenumber']);

        if (!$client) {
            return null;
        }

        return normalizeSmsPhone('', $client->phonenumber ?? '');
    } catch (\Throwable $e) {
        logActivity("Unecast Client Phone DB Error: " . $e->getMessage());
        return null;
    }
}

function getClientByEmail($emailAddress): ?array
{
    $emailAddress = trim((string)$emailAddress);

    if ($emailAddress === '') {
        return null;
    }

    try {
        $result = localAPI("GetClientsDetails", [
            "email" => $emailAddress,
            "stats" => false,
        ]);

        if (($result["result"] ?? '') !== "success") {
            logActivity("Unecast GetClientByEmail failed: " . json_encode($result));
            return null;
        }

        $client = $result['client'] ?? $result;

        return [
            'client_id'   => (int)($client['id'] ?? $client['userid'] ?? $client['clientid'] ?? 0),
            'phonenumber' => $client['phonenumber'] ?? $client['phone'] ?? '',
        ];
    } catch (\Throwable $e) {
        logActivity("Unecast GetClientByEmail Error: " . $e->getMessage());
        return null;
    }
}

function normalizeSmsPhone($phoneCc, $phoneNumber): string
{
    $cc  = preg_replace('/\D/', '', (string)$phoneCc);
    $num = preg_replace('/\D/', '', (string)$phoneNumber);

    if ($num === '') {
        return '';
    }

    // Convert 0094xxxxxxxxx to 94xxxxxxxxx.
    if (strpos($num, '00') === 0) {
        $num = substr($num, 2);
    }

    // If number already starts with supplied country code, return it.
    if ($cc !== '' && strpos($num, $cc) === 0) {
        return $num;
    }

    // Remove leading 0 when country code exists.
    if ($cc !== '' && $num[0] === '0') {
        $num = substr($num, 1);
    }

    if ($cc !== '') {
        return $cc . $num;
    }

    // Sri Lanka fallback examples:
    // 0771234567  -> 94771234567
    // 771234567   -> 94771234567
    // 94771234567 -> 94771234567
    if (strpos($num, '94') === 0 && strlen($num) >= 11) {
        return $num;
    }

    if ($num[0] === '0') {
        return '94' . substr($num, 1);
    }

    if (strlen($num) === 9 && $num[0] === '7') {
        return '94' . $num;
    }

    return $num;
}

function buildHtmlForm()
{
    return '
        <div class="d-inline-flex">
            <form method="post">
                <div class="d-flex">
                    <input type="text" class="form-control form-control-lg" name="code" placeholder="Enter Auth Code" autocomplete="one-time-code">
                </div>
                <div class="d-flex mt-2">
                    <input type="submit" value="Verify" class="btn btn-lg btn-primary">
                </div>
            </form>
        </div>
    ';
}

// ---------------------------------------------------------------
// SMS + EMAIL SENDERS
// ---------------------------------------------------------------
function sendAuthCodeSms($apiKey, $phoneNumber, $authCode): bool
{
    $apiKey      = trim((string)$apiKey);
    $phoneNumber = normalizeSmsPhone('', $phoneNumber);

    if ($apiKey === '' || $phoneNumber === '') {
        logActivity("Unecast SMS skipped (missing apiKey/phone). Phone=" . json_encode($phoneNumber));
        return false;
    }

    $smsData = [
        "from"    => "Webbyus",
        "to"      => $phoneNumber,
        "message" => "Webbyus OTP: {$authCode} to login.",
    ];

    logActivity("Unecast SMS Request: " . json_encode($smsData));

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.unecast.com/v1.0/sms/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($smsData),
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        logActivity("Unecast SMS cURL Error: " . curl_error($curl));
        curl_close($curl);
        return false;
    }

    curl_close($curl);

    logActivity("Unecast SMS HTTP Code: {$httpCode}");
    logActivity("Unecast SMS Response: " . $response);

    $responseData = json_decode($response, true);

    if (!is_array($responseData)) {
        return ($httpCode >= 200 && $httpCode < 300);
    }

    $status = $responseData['status'] ?? $responseData['success'] ?? null;

    if (is_bool($status)) {
        return $status;
    }

    if (is_string($status)) {
        return in_array(strtolower($status), ['true', 'success', 'sent', 'ok', 'queued'], true);
    }

    return ($httpCode >= 200 && $httpCode < 300);
}

function sendAuthCodeEmailToAdmin($adminId, $adminUsername, $code): bool
{
    $adminId = (int)$adminId;

    if ($adminId <= 0) {
        return false;
    }

    try {
        // SendAdminEmail can vary between WHMCS versions. Log result instead of breaking SMS.
        $results = localAPI('SendAdminEmail', [
            "id"            => $adminId,
            "customtype"    => "general",
            "customsubject" => "Login Auth Code",
            "custommessage" => "Your verification code is: {$code}",
        ], $adminUsername);

        if (($results["result"] ?? '') !== "success") {
            logActivity("Unecast Admin Email Failed: " . json_encode($results));
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        logActivity("Unecast Admin Email Error: " . $e->getMessage());
        return false;
    }
}

function sendAuthCodeEmailToClient($clientId, $code): bool
{
    $clientId = (int)$clientId;

    if ($clientId <= 0) {
        return false;
    }

    try {
        $results = localAPI("SendEmail", [
            "messagename"   => "Client Signup Email",
            "id"            => $clientId,
            "customtype"    => "general",
            "customsubject" => "Login Auth Code",
            "custommessage" => "Your verification code is: {$code}",
        ]);

        if (($results["result"] ?? '') !== "success") {
            logActivity("Unecast Client Email Failed: " . json_encode($results));
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        logActivity("Unecast Client Email Error: " . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------
// UNECAST ACCOUNT HELPERS
// ---------------------------------------------------------------
function getUnecastStoredApiKey()
{
    try {
        $row = Capsule::table('tblconfiguration')
            ->where('setting', '2fasettings')
            ->first();

        if (!$row) {
            return null;
        }

        $settings = @unserialize($row->value);

        if (!is_array($settings)) {
            return null;
        }

        return $settings['modules']['unecast']['api_key'] ?? null;
    } catch (\Throwable $e) {
        logActivity("Unecast Stored API Key Error: " . $e->getMessage());
        return null;
    }
}

function getUnecastBalance()
{
    $apiKey = getUnecastStoredApiKey();

    if (empty($apiKey)) {
        logActivity("Unecast Balance Check: API key missing.");
        return null;
    }

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.unecast.com/v1.0/account/balance",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}",
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        logActivity("Unecast Balance cURL Error: " . curl_error($curl));
        curl_close($curl);
        return null;
    }

    curl_close($curl);

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logActivity("Unecast Balance: Invalid JSON - " . $response);
        return null;
    }

    if (isset($data['balance'])) {
        logActivity("Unecast Balance: " . $data['balance']);
        return $data;
    }

    logActivity("Unecast Balance HTTP Code: {$httpCode}");
    logActivity("Unecast Balance: Unexpected response - " . $response);

    return null;
}
