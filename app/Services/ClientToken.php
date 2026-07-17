<?php

namespace App\Services;

use App\Models\ClientApiToken;
use App\Models\User;

/**
 * Hashed, revocable tokens for native clients.
 */
class ClientToken
{
    public const ACCESS_TYPE = 'access';
    public const REFRESH_TYPE = 'refresh';
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 2592000;

    /**
     * @param User $user
     * @param string $platform
     * @param string $deviceName
     * @param string|null $familyId
     * @return array
     */
    public static function issuePair(User $user, $platform, $deviceName, $familyId = null)
    {
        $familyId = $familyId ?: bin2hex(random_bytes(16));
        $now = time();
        $access = self::storeToken($user, self::ACCESS_TYPE, 'ca_', $familyId, $platform, $deviceName, $now + self::ACCESS_TTL);
        $refresh = self::storeToken($user, self::REFRESH_TYPE, 'cr_', $familyId, $platform, $deviceName, $now + self::REFRESH_TTL);

        return array(
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'refresh_expires_in' => self::REFRESH_TTL,
        );
    }

    /**
     * @param string $rawToken
     * @param string $type
     * @return ClientApiToken|null
     */
    public static function findValid($rawToken, $type)
    {
        if (!is_string($rawToken) || $rawToken === '') {
            return null;
        }

        return ClientApiToken::where('token_hash', hash('sha256', $rawToken))
            ->where('token_type', $type)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', time())
            ->first();
    }

    /**
     * Rotate a refresh token. Reuse of the old token fails after the first use.
     *
     * @param string $rawToken
     * @return array|null
     */
    public static function rotate($rawToken)
    {
        if (!is_string($rawToken) || $rawToken === '') {
            return null;
        }

        $token = ClientApiToken::where('token_hash', hash('sha256', $rawToken))
            ->where('token_type', self::REFRESH_TYPE)
            ->first();
        if ($token === null || (int)$token->expires_at <= time()) {
            return null;
        }
        if ($token->revoked_at !== null) {
            // A rotated refresh token was replayed: invalidate its current family.
            self::revokeFamily($token->family_id);
            return null;
        }

        $updated = ClientApiToken::where('id', $token->id)
            ->whereNull('revoked_at')
            ->update(array('revoked_at' => time()));
        if ($updated !== 1) {
            return null;
        }

        $user = User::find($token->user_id);
        if ($user === null) {
            return null;
        }

        return self::issuePair($user, $token->platform, $token->device_name, $token->family_id);
    }

    /**
     * @param string $familyId
     * @return int
     */
    public static function revokeFamily($familyId)
    {
        return ClientApiToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(array('revoked_at' => time()));
    }

    /**
     * @param mixed $request
     * @return string|null
     */
    public static function bearerFromRequest($request)
    {
        $header = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    /**
     * @param User $user
     * @param string $type
     * @param string $prefix
     * @param string $familyId
     * @param string $platform
     * @param string $deviceName
     * @param int $expiresAt
     * @return string
     */
    private static function storeToken(User $user, $type, $prefix, $familyId, $platform, $deviceName, $expiresAt)
    {
        $raw = $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = new ClientApiToken();
        $token->user_id = $user->id;
        $token->token_hash = hash('sha256', $raw);
        $token->token_type = $type;
        $token->family_id = $familyId;
        $token->platform = $platform;
        $token->device_name = function_exists('mb_substr')
            ? mb_substr((string)$deviceName, 0, 128, 'UTF-8')
            : substr((string)$deviceName, 0, 128);
        $token->created_at = time();
        $token->expires_at = $expiresAt;
        $token->revoked_at = null;
        $token->save();

        return $raw;
    }
}
