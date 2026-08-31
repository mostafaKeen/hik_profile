<?php
require_once(__DIR__ . '/settings.php');

class CRest
{
    /**
     * Call a Bitrix24 REST API method.
     *
     * @param string $method Method name (e.g. 'crm.item.list')
     * @param array $params Query parameters
     * @return array Response payload
     */
    public static function call($method, $params = [])
    {
        $settingsFile = __DIR__ . '/settings.json';
        if (!file_exists($settingsFile)) {
            return [
                'error' => 'no_settings',
                'error_description' => 'No settings.json found. Please run install.php first.'
            ];
        }

        $settings = json_decode(file_get_contents($settingsFile), true);
        if (!$settings || empty($settings['access_token']) || empty($settings['domain'])) {
            return [
                'error' => 'invalid_settings',
                'error_description' => 'settings.json is empty or invalid.'
            ];
        }

        // Standard call uses the stored client credentials and access token
        $params['auth'] = $settings['access_token'];
        $domain = $settings['domain'];

        $url = "https://{$domain}/rest/{$method}.json";
        $response = self::makeRequest($url, $params);

        // If token is expired, attempt to refresh it
        if (isset($response['error']) && ($response['error'] === 'expired_token' || $response['error'] === 'invalid_token')) {
            $refreshResult = self::refreshToken($settings);
            if ($refreshResult && isset($refreshResult['access_token'])) {
                $params['auth'] = $refreshResult['access_token'];
                $response = self::makeRequest($url, $params);
            }
        }

        return $response;
    }

    /**
     * Perform HTTP POST request via cURL.
     */
    protected static function makeRequest($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // compatibility for local environments
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'error' => 'curl_error',
                'error_description' => $error
            ];
        }
        
        curl_close($ch);
        return json_decode($res, true) ?: [];
    }

    /**
     * Refreshes the OAuth access token.
     */
    protected static function refreshToken($settings)
    {
        if (empty($settings['refresh_token'])) {
            return false;
        }

        $url = "https://oauth.bitrix.info/oauth/token/";
        $data = [
            'grant_type' => 'refresh_token',
            'client_id' => C_REST_CLIENT_ID,
            'client_secret' => C_REST_CLIENT_SECRET,
            'refresh_token' => $settings['refresh_token']
        ];

        $res = self::makeRequest($url, $data);
        if (isset($res['access_token'])) {
            $settings['access_token'] = $res['access_token'];
            $settings['refresh_token'] = $res['refresh_token'];
            self::setSettingData($settings);
        }

        return $res;
    }

    /**
     * Write settings to local JSON file.
     */
    public static function setSettingData($settings)
    {
        return file_put_contents(__DIR__ . '/settings.json', json_encode($settings, JSON_PRETTY_PRINT));
    }
}

class CRestCurrent extends CRest
{
    /**
     * Call method in context of the current active user.
     * Uses tokens sent dynamically in the request frame body.
     */
    public static function call($method, $params = [])
    {
        // Extract authorization credentials from request POST or GET
        $authId = $_REQUEST['AUTH_ID'] ?? '';
        $domain = $_REQUEST['DOMAIN'] ?? '';

        if (!empty($authId) && !empty($domain)) {
            $params['auth'] = htmlspecialchars($authId);
            $url = "https://" . htmlspecialchars($domain) . "/rest/{$method}.json";
            return parent::makeRequest($url, $params);
        }

        // Fallback to saved admin credentials if not called in widget iframe context
        return parent::call($method, $params);
    }
}
?>
