<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Application Installation</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 40px;
            width: 480px;
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
        }
        .icon {
            font-size: 48px;
            color: #10b981;
            margin-bottom: 20px;
            animation: bounce 2s infinite;
        }
        h2 {
            margin: 0 0 10px;
            font-weight: 600;
        }
        p {
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .info {
            background: rgba(15, 23, 42, 0.6);
            border-radius: 8px;
            padding: 16px;
            font-family: monospace;
            font-size: 12px;
            text-align: left;
            word-break: break-all;
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.2);
        }
        .footer {
            margin-top: 30px;
            font-size: 11px;
            color: #64748b;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
    </style>
</head>
<body>
    <div class="card">
        <?php
        require_once(__DIR__ . '/crest.php');

        // Check if installer is called from Bitrix24
        $authId = $_REQUEST['AUTH_ID'] ?? '';
        $domain = $_REQUEST['DOMAIN'] ?? '';
        $refreshId = $_REQUEST['REFRESH_ID'] ?? '';
        $appSid = $_REQUEST['APP_SID'] ?? '';

        if (empty($authId) || empty($domain)) {
            echo '<div class="icon" style="color: #ef4444;">⚠️</div>';
            echo '<h2>Installation Failed</h2>';
            echo '<p>This script must be launched from within your Bitrix24 portal.</p>';
            exit;
        }

        // Save incoming credentials to settings.json
        $settings = [
            'access_token' => htmlspecialchars($authId),
            'domain' => htmlspecialchars($domain),
            'refresh_token' => htmlspecialchars($refreshId),
            'application_token' => htmlspecialchars($appSid)
        ];
        CRest::setSettingData($settings);

        // Dynamically build the absolute URL to index.php
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $uri = preg_replace('/install\.php$/i', 'index.php', $_SERVER['REQUEST_URI']);
        $handlerUrl = $protocol . $host . $uri;

        // Perform clean registration: call placement.unbind, then placement.bind
        // We use CRestCurrent to invoke binding/unbinding on behalf of the installing admin
        CRestCurrent::call('placement.unbind', [
            'PLACEMENT' => 'USER_PROFILE_TOOLBAR',
            'HANDLER' => $handlerUrl
        ]);

        $bindResult = CRestCurrent::call('placement.bind', [
            'PLACEMENT' => 'USER_PROFILE_TOOLBAR',
            'HANDLER' => $handlerUrl,
            'TITLE' => 'Hikvision & CRM Profile'
        ]);

        if (isset($bindResult['result']) && $bindResult['result'] === true) {
            echo '<div class="icon">✅</div>';
            echo '<h2>Application Installed!</h2>';
            echo '<p>The widget has been successfully registered to the employee profile card toolbar.</p>';
            echo '<div class="info">';
            echo '<strong>Domain:</strong> ' . htmlspecialchars($domain) . '<br>';
            echo '<strong>Handler:</strong> ' . htmlspecialchars($handlerUrl) . '<br>';
            echo '<strong>Status:</strong> Placement Bound Successfully';
            echo '</div>';
        } else {
            echo '<div class="icon" style="color: #f59e0b;">⚠️</div>';
            echo '<h2>Partial Installation</h2>';
            echo '<p>Credentials saved, but could not bind placement menu.</p>';
            echo '<div class="info">';
            echo '<strong>Error:</strong> ' . htmlspecialchars($bindResult['error'] ?? 'Unknown Error') . '<br>';
            echo '<strong>Description:</strong> ' . htmlspecialchars($bindResult['error_description'] ?? 'Could not bind placement.') . '<br>';
            echo '<strong>Endpoint:</strong> ' . htmlspecialchars($handlerUrl);
            echo '</div>';
        }
        ?>
        <div class="footer">
            Capital Western Group &bull; Hikvision Integration App
        </div>
    </div>
    
    <!-- Bitrix24 JS Library needed to finalize application installation in portal iframe -->
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <script>
        // Call BX24.init to notify the portal that installation is complete
        if (typeof BX24 !== 'undefined') {
            BX24.init(function() {
                BX24.installFinish();
            });
        }
    </script>
</body>
</html>
