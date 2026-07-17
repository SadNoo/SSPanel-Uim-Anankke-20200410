<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;
use App\Models\LoginIp;
use App\Models\User;
use App\Services\ClientConfig;
use App\Services\ClientToken;
use App\Services\Config;
use App\Utils\GA;
use App\Utils\Hash;
use App\Utils\Tools;

/**
 * Versioned API used by first-party iOS, Android, macOS and Windows clients.
 */
class ClientApiV1Controller extends BaseController
{
    private const PLATFORMS = array('ios', 'android', 'macos', 'windows', 'linux');

    public function capabilities($request, $response, $args)
    {
        return $this->json($request, $response, 200, array(
            'api_version' => 'v1',
            'platforms' => self::PLATFORMS,
            'config_formats' => array('mihomo', 'links'),
            'authentication' => array(
                'password' => true,
                'totp' => true,
                'refresh_token_rotation' => true,
                'captcha_required' => Config::get('enable_login_captcha') === 'true',
            ),
            'subscription' => array(
                'update_interval_minutes' => 60,
                'ss2022_single_port' => true,
                'ss2022_method' => '2022-blake3-aes-256-gcm',
            ),
        ));
    }

    public function login($request, $response, $args)
    {
        if (Config::get('enable_login_captcha') === 'true') {
            return $this->error(
                $request,
                $response,
                409,
                'CAPTCHA_REQUIRED',
                '面板已启用登录验证码，请先使用网页完成验证或为客户端 API 配置独立风控。',
                array('login_url' => rtrim(Config::get('baseUrl'), '/') . '/auth/login')
            );
        }

        $input = $this->input($request);
        $email = strtolower(trim(isset($input['email']) ? (string)$input['email'] : ''));
        $password = isset($input['password']) ? (string)$input['password'] : '';
        if ($password === '' && isset($input['passwd'])) {
            $password = (string)$input['passwd'];
        }
        $platform = $this->normalizePlatform(isset($input['platform']) ? $input['platform'] : 'ios');
        $deviceName = trim(isset($input['device_name']) ? (string)$input['device_name'] : '');

        if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > 1024 || $platform === null) {
            return $this->error($request, $response, 422, 'INVALID_REQUEST', 'email、password 和受支持的 platform 为必填项。');
        }

        $user = User::where('email', $email)->first();
        if ($user === null || !Hash::checkPassword($user->pass, $password)) {
            if ($user !== null) {
                $this->logLogin($request, $user->id, 1);
            }
            return $this->error($request, $response, 401, 'INVALID_CREDENTIALS', '邮箱或密码错误。');
        }

        if ((int)$user->ga_enable === 1) {
            $totp = trim(isset($input['totp_code']) ? (string)$input['totp_code'] : '');
            if ($totp === '') {
                return $this->error($request, $response, 401, 'TOTP_REQUIRED', '该账户需要两步验证码。');
            }
            $ga = new GA();
            if (!$ga->verifyCode($user->ga_token, $totp)) {
                $this->logLogin($request, $user->id, 1);
                return $this->error($request, $response, 401, 'INVALID_TOTP', '两步验证码错误。');
            }
        }

        $tokens = ClientToken::issuePair($user, $platform, $deviceName);
        $this->logLogin($request, $user->id, 0);

        return $this->json($request, $response, 200, array(
            'tokens' => $tokens,
            'user' => $this->userData($user),
        ));
    }

    public function refresh($request, $response, $args)
    {
        $input = $this->input($request);
        $refreshToken = isset($input['refresh_token']) ? trim((string)$input['refresh_token']) : '';
        $tokens = ClientToken::rotate($refreshToken);
        if ($tokens === null) {
            return $this->error($request, $response, 401, 'INVALID_REFRESH_TOKEN', '刷新令牌无效、已过期或已被使用。');
        }
        return $this->json($request, $response, 200, array('tokens' => $tokens));
    }

    public function logout($request, $response, $args)
    {
        $context = $this->accessContext($request);
        if ($context === null) {
            return $this->error($request, $response, 401, 'UNAUTHORIZED', '访问令牌无效或已过期。');
        }
        ClientToken::revokeFamily($context['token']->family_id);
        return $this->json($request, $response, 200, array('logged_out' => true));
    }

    public function me($request, $response, $args)
    {
        $context = $this->accessContext($request);
        if ($context === null) {
            return $this->error($request, $response, 401, 'UNAUTHORIZED', '访问令牌无效或已过期。');
        }
        return $this->json($request, $response, 200, $this->userData($context['user']));
    }

    public function subscription($request, $response, $args)
    {
        $context = $this->accessContext($request);
        if ($context === null) {
            return $this->error($request, $response, 401, 'UNAUTHORIZED', '访问令牌无效或已过期。');
        }

        $user = $context['user'];
        $platform = $this->normalizePlatform($context['token']->platform) ?: 'ios';
        return $this->json($request, $response, 200, array(
            'status' => $this->accountStatus($user),
            'quota' => $this->quotaData($user),
            'update_interval_minutes' => 60,
            'platform' => $platform,
            'config_url' => '/api/client/v1/subscription/config?platform=' . rawurlencode($platform) . '&format=mihomo',
            'supported_platforms' => self::PLATFORMS,
            'supported_formats' => array('mihomo', 'links'),
        ));
    }

    public function config($request, $response, $args)
    {
        $context = $this->accessContext($request);
        if ($context === null) {
            return $this->error($request, $response, 401, 'UNAUTHORIZED', '访问令牌无效或已过期。');
        }

        $user = $context['user'];
        $status = $this->accountStatus($user);
        if ($status !== 'active') {
            return $this->error($request, $response, 403, 'SUBSCRIPTION_UNAVAILABLE', '账户当前不可使用订阅。', array('status' => $status));
        }

        $query = $request->getQueryParams();
        $platform = $this->normalizePlatform(isset($query['platform']) ? $query['platform'] : $context['token']->platform);
        $format = strtolower(trim(isset($query['format']) ? (string)$query['format'] : 'mihomo'));
        if ($platform === null || !in_array($format, array('mihomo', 'links'), true)) {
            return $this->error($request, $response, 422, 'INVALID_REQUEST', 'platform 或 format 不受支持。');
        }

        if ($format === 'mihomo') {
            if (count(ClientConfig::proxies($user)) === 0) {
                return $this->error($request, $response, 404, 'NO_AVAILABLE_NODES', '当前账户没有可用节点。');
            }
            $content = ClientConfig::mihomo($user, $platform);
            $contentType = 'text/yaml; charset=utf-8';
            $filename = 'mihomo-' . $platform . '.yaml';
        } else {
            $content = ClientConfig::links($user);
            if ($content === '') {
                return $this->error($request, $response, 404, 'NO_AVAILABLE_NODES', '当前账户没有可用节点。');
            }
            $contentType = 'text/plain; charset=utf-8';
            $filename = 'subscription-' . $platform . '.txt';
        }

        $etag = '"' . hash('sha256', $content) . '"';
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, no-cache, must-revalidate')
            ->withHeader('Subscription-Userinfo', $this->subscriptionUserInfo($user))
            ->withHeader('Profile-Update-Interval', '1')
            ->withHeader('X-Config-Platform', $platform)
            ->withHeader('X-Request-ID', $this->requestId($request));

        if (strpos($request->getHeaderLine('If-None-Match'), $etag) !== false) {
            return $response->withStatus(304);
        }

        $response = $response
            ->withStatus(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->getBody()->write($content);
        return $response;
    }

    private function accessContext($request)
    {
        $rawToken = ClientToken::bearerFromRequest($request);
        $token = ClientToken::findValid($rawToken, ClientToken::ACCESS_TYPE);
        if ($token === null) {
            return null;
        }
        $user = User::find($token->user_id);
        if ($user === null) {
            ClientToken::revokeFamily($token->family_id);
            return null;
        }
        return array('token' => $token, 'user' => $user);
    }

    private function input($request)
    {
        $input = $request->getParsedBody();
        if (is_array($input)) {
            return $input;
        }
        $raw = (string)$request->getBody();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function normalizePlatform($platform)
    {
        $platform = strtolower(trim((string)$platform));
        $aliases = array('mac' => 'macos', 'osx' => 'macos', 'pc' => 'windows', 'win' => 'windows');
        if (isset($aliases[$platform])) {
            $platform = $aliases[$platform];
        }
        return in_array($platform, self::PLATFORMS, true) ? $platform : null;
    }

    private function accountStatus(User $user)
    {
        if ((int)$user->enable !== 1) {
            return 'disabled';
        }
        if (strtotime($user->expire_in) <= time()) {
            return 'expired';
        }
        if ((float)$user->transfer_enable <= (float)$user->u + (float)$user->d) {
            return 'traffic_exhausted';
        }
        return 'active';
    }

    private function userData(User $user)
    {
        return array(
            'id' => (int)$user->id,
            'email' => (string)$user->email,
            'name' => (string)$user->user_name,
            'status' => $this->accountStatus($user),
            'expires_at' => (string)$user->expire_in,
            'quota' => $this->quotaData($user),
        );
    }

    private function quotaData(User $user)
    {
        $upload = (int)$user->u;
        $download = (int)$user->d;
        $total = (int)$user->transfer_enable;
        return array(
            'upload' => $upload,
            'download' => $download,
            'used' => $upload + $download,
            'total' => $total,
            'remaining' => max(0, $total - $upload - $download),
        );
    }

    private function subscriptionUserInfo(User $user)
    {
        $expire = strtotime($user->expire_in);
        return 'upload=' . (int)$user->u
            . '; download=' . (int)$user->d
            . '; total=' . (int)$user->transfer_enable
            . '; expire=' . ($expire > 0 ? $expire : 0);
    }

    private function logLogin($request, $userId, $type)
    {
        $server = $request->getServerParams();
        $ip = isset($server['REMOTE_ADDR']) ? Tools::getRealIp($server['REMOTE_ADDR']) : '0.0.0.0';
        $login = new LoginIp();
        $login->ip = $ip;
        $login->userid = $userId;
        $login->datetime = time();
        $login->type = $type;
        $login->save();
    }

    private function error($request, $response, $status, $code, $message, $details = array())
    {
        return $this->json($request, $response, $status, null, array(
            'code' => $code,
            'message' => $message,
            'details' => (object)$details,
        ));
    }

    private function json($request, $response, $status, $data, $error = null)
    {
        $requestId = $this->requestId($request);
        $payload = array(
            'ok' => $error === null,
            'data' => $data,
            'error' => $error,
            'meta' => array(
                'request_id' => $requestId,
                'api_version' => 'v1',
                'server_time' => time(),
            ),
        );
        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Request-ID', $requestId);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }

    private function requestId($request)
    {
        $requestId = trim($request->getHeaderLine('X-Request-ID'));
        if ($requestId !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $requestId) === 1) {
            return $requestId;
        }
        return bin2hex(random_bytes(12));
    }
}
