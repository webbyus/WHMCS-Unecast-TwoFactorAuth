<?php

require_once __DIR__ . "../../../../init.php";
require_once __DIR__ . "../../../../configuration.php";

use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\Session;

function unecast_config()
{
    return [
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "Unecast",
        ],
        "Description" => [
            "Type" => "System",
            "Value" => "Hello"
        ],
       "ShortDescription" => [
            "Type"  => "System",
            "Value" => "Secure 2FA with SMS/Email via Unecast", 
        ],
        "api_key" => [
            "FriendlyName" => "API Key",
            "Type" => "textarea",
            "Rows" => 2,
            "Description" => "Purchase the API key from Unecast to enable integration. The auth code will, by default, be sent to the admin's email address.",
        ],
        "disable_sms" => [
            "FriendlyName" => "Disable SMS",
            "Type" => "yesno",
            "Description" => "Tick to disable SMS as a 2FA method",
        ],
        "disable_email" => [
            "FriendlyName" => "Disable Email",
            "Type" => "yesno",
            "Description" => "Tick to disable Email as a 2FA method",
        ],
    ];
}

function unecast_challenge($params)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    //Admin Login
    $adminId = $_SESSION['2faadminid'] ?? null;
    $adminInfo = $adminId ? getAdminInfo($adminId) : null;
    $adminUsername = $adminInfo->username ?? null;
    $adminEmail = $adminInfo->email ?? null;

    $apiKey = $params["settings"]["api_key"];
   
    $disableSms = !empty($params["settings"]["disable_sms"]);
    $disableEmail = !empty($params["settings"]["disable_email"]);
    
   

    $code = rand(100000, 999999);
    $_SESSION["smsCode"] = $code;

    $emailAddress = $params["user_info"]["email"] ?? null;
    $clientInfo = $emailAddress ? getClientId($emailAddress) : null;

    $phoneCc = $clientInfo['phonecc'] ?? "";
    $phoneNumber = $phoneCc . ($clientInfo['phonenumber'] ?? "");
    $adminPhoneNumber = getAdminMobileFromAuthdata($adminId);
    logActivity("Unecast Recieiver's Phone Number : " . json_encode($adminPhoneNumber));
    
    
    
   
    $clientId = $clientInfo['client_id'] ?? null;
 logActivity("Unecast admin id : " . json_encode($adminId));
    // Send to admin
    if ($adminId) {
        if (!$disableEmail) {
            sendAuthCodeEmailToAdmin($adminId, $adminUsername, $code);
        }
        
         logActivity("Unecast disable SMS Checlk : " . json_encode(!$disableSms));
       
        if (!$disableSms) {
            sendAuthCodeSms($apiKey, $adminPhoneNumber, $code);
        }
    }

    // Send to client
    if ($clientId) {
        if (!$disableEmail) {
            sendAuthCodeEmailToClient($clientId, $code);
        }
        if (!$disableSms && !empty($phoneNumber)) {
            sendAuthCodeSms($apiKey, $phoneNumber, $code);
        }
    }

    return buildHtmlForm();
}

function getAdminInfo($adminId)
{
    return Capsule::table('tbladmins')
        ->where('id', $adminId)
        ->first(['firstname', 'lastname', 'email', 'username']);
}

function buildHtmlForm()
{
    $htmlFormCode = "";
    $htmlFormCode .= '<div class="d-inline-flex">';
    $htmlFormCode .= '<form method="post">';
    $htmlFormCode .= '<div class="d-flex">';
    $htmlFormCode .= '<input type="text" class="form-control form-control-lg" name="code" placeholder="Enter Auth Code">';
    $htmlFormCode .= "</div>";
    $htmlFormCode .= '<div class="d-flex">';
    $htmlFormCode .= '<input type="submit" value="Verify" class="btn btn-lg btn-primary">';
    $htmlFormCode .= "</div>";
    $htmlFormCode .= "</form>";
    $htmlFormCode .= "</div>";

    return $htmlFormCode;
}

function unecast_verify($params)
{
    $userCode = $_POST["code"] ?? null;
    $validCode = $_SESSION["smsCode"] ?? null;

    return ($userCode && $validCode && $userCode == $validCode);
}

// Send SMS
function sendAuthCodeSms($apiKey, $phoneNumber, $authCode)
{
    $smsData = [
        "from" => "Webbyus",
        "to" =>  $phoneNumber,
        "message" => "Webbyus OTP: {$authCode} to login.",
     ];
    
    logActivity("Request : " . json_encode($smsData));
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.unecast.com/v1.0/sms/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($smsData),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey",
        ],
    ]);

    $response = curl_exec($curl);
    logActivity("Unecast Response : " . json_encode($response));
    $response_data = json_decode($response, true);

   
    curl_close($curl);

    return isset($response_data['status']) && $response_data['status'];
}

//Send Email To Admin
function sendAuthCodeEmailToAdmin($adminId, $adminUsername, $code)
{
    $command = 'SendAdminEmail';
    $postData = [
        "messagename" => "Client Signup Email",
        "id" => $adminId,
        "customtype" => "general",
        "customsubject" => "Login Auth Code",
        "custommessage" => "Your verification code is: $code",
    ];

    $results = localAPI($command, $postData, $adminUsername);
    return $results["result"] === "success";
}

// Send Email to a client
function sendAuthCodeEmailToClient($clientId, $code)
{
    $postData = [
        "messagename" => "Client Signup Email",
        "id" => $clientId,
        "customtype" => "general",
        "customsubject" => "Login Auth Code",
        "custommessage" => "Your verification code is: $code",
    ];
    $results = localAPI("SendEmail", $postData);
    return $results["result"] === "success";
}

//Get Client Id
function getClientId($emailAddress)
{
    $postData = [
        "email" => $emailAddress,
        "stats" => false,
    ];
    $result = localAPI("GetClientsDetails", $postData);
    return $result["result"] === "success" ? $result : null;
}



function unecast_activate($params)
{
    // Show form to enter mobile number when enabling
    return '
        <form method="post" action="">
            <label for="admin_mobile">Enter your mobile number</label>
            <input type="text" name="admin_mobile" placeholder="Eg: 947XXXXXXXX" class="form-control" required>
            <br>
            <input type="submit" value="Enable 2FA" class="btn btn-primary">
        </form>
    ';
}

function unecast_activateverify($params)
{
    $adminId = $_SESSION['adminid'] ?? 0;
    $adminMobile = $params['post_vars']['admin_mobile'] ?? '';

    if (!$adminId || empty($adminMobile)) {
        return ['success' => false, 'error' => 'Mobile number is required'];
    }

    // Save into tbladmins.authdata
    setAdminMobileInAuthdata($adminId, $adminMobile);

    return [
        'success' => true,
        'settings' => [
            'admin_mobile' => $adminMobile
        ]
    ];
}


function getAdminMobileFromAuthdata(int $adminId): ?string {
    $row = Capsule::table('tbladmins')
        ->where('id', $adminId)
        ->first(['authdata']);

    if (!$row || empty($row->authdata)) {
        return null;
    }

    $data = json_decode($row->authdata, true);

    return $data['admin_mobile'] ?? null;
}

function unecast_deactivate($params)
{
    logActivity('Unecast: deactivate called: ' . json_encode($params));

    // 1) Get the current user id from module params
    $adminId = (int)($params['user_info']['id'] ?? 0);

    // fallback (not usually needed)
    if (!$adminId) {
        $adminId = (int)($_SESSION['adminid'] ?? 0);
    }
    if (!$adminId) {
        // if we still don't have an id, stop gracefully
        logActivity('Unecast: deactivate – no admin id');
        return true; // allow WHMCS to finish deactivation
    }

    // 2) Load current authdata JSON
    $row = Capsule::table('tbladmins')->where('id', $adminId)->first(['authdata']);
    $data = $row && $row->authdata ? json_decode($row->authdata, true) : [];

    // 3) Remove Unecast-related keys
    unset($data['admin_mobile'], $data['backupcode']);

    // 4) Save back (use {} when empty to keep column valid)
    Capsule::table('tbladmins')
        ->where('id', $adminId)
        ->update(['authdata' => $data ? json_encode($data, JSON_UNESCAPED_SLASHES) : '{}']);

    logActivity("Unecast: admin {$adminId} – mobile cleared on deactivation");

    // 5) MUST return true to tell WHMCS “ok, deactivated”
    return true;
}





    
