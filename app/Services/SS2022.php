<?php

namespace App\Services;

use App\Models\Node;
use App\Models\User;
use InvalidArgumentException;

/**
 * SS2022 multi-user single-port helpers shared by the panel and subscriptions.
 *
 * The key derivation intentionally matches the local sshappy backend.
 */
class SS2022
{
    public const NODE_SORT = 14;
    public const METHOD = '2022-blake3-aes-256-gcm';
    public const KEY_LENGTH = 32;

    /**
     * Parse ss_node.server in the form host;port;server_key_base64.
     *
     * @param string $value
     * @return array
     */
    public static function parseServer($value)
    {
        $parts = explode(';', trim((string)$value));
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('SS2022 节点地址格式应为 host;port;server_key_base64');
        }

        $host = trim($parts[0]);
        $portValue = trim($parts[1]);
        $serverKey = trim($parts[2]);
        $port = ($portValue === '' || $portValue === '0') ? 443 : (int)$portValue;

        $validIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $validHostname = strlen($host) <= 253 && preg_match(
            '/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
            $host
        ) === 1;
        if ($host === '' || (!$validIp && !$validHostname)) {
            throw new InvalidArgumentException('SS2022 节点 host 无效');
        }
        if ($port < 1 || $port > 65535 || ($portValue !== '' && $portValue !== '0' && !ctype_digit($portValue))) {
            throw new InvalidArgumentException('SS2022 节点端口必须在 1 到 65535 之间');
        }
        if ($serverKey === '' || !self::isValidKey($serverKey)) {
            throw new InvalidArgumentException('SS2022 server key 必须是可解码为 32 字节的标准 Base64');
        }

        return array(
            'host' => $host,
            'port' => $port,
            'server_key' => $serverKey,
        );
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function isValidKey($key)
    {
        $decoded = base64_decode($key, true);
        return $decoded !== false && strlen($decoded) === self::KEY_LENGTH;
    }

    /**
     * Derive the per-user key exactly as sshappy/sshappy/keys.py does.
     *
     * @param User $user
     * @param Node $node
     * @return string
     */
    public static function deriveUserKey(User $user, Node $node)
    {
        $server = self::parseServer($node->server);
        $material = implode('|', array(
            (string)$user->id,
            (string)$user->passwd,
            self::METHOD,
            (string)$node->id,
            $server['server_key'],
        ));

        return base64_encode(hash('sha256', $material, true));
    }

    /**
     * @param User $user
     * @param Node $node
     * @return string
     */
    public static function clientPassword(User $user, Node $node)
    {
        $server = self::parseServer($node->server);
        return $server['server_key'] . ':' . self::deriveUserKey($user, $node);
    }

    /**
     * @param User $user
     * @param Node $node
     * @return array
     */
    public static function mihomoProxy(User $user, Node $node)
    {
        $server = self::parseServer($node->server);
        return array(
            'name' => (string)$node->name,
            'type' => 'ss',
            'server' => $server['host'],
            'port' => $server['port'],
            'cipher' => self::METHOD,
            'password' => self::clientPassword($user, $node),
            'udp' => true,
        );
    }

    /**
     * Build a SIP002 link accepted by mihomo.
     *
     * @param User $user
     * @param Node $node
     * @return string
     */
    public static function sip002Link(User $user, Node $node)
    {
        $server = self::parseServer($node->server);
        $userinfo = self::base64UrlEncode(self::METHOD . ':' . self::clientPassword($user, $node));
        $host = strpos($server['host'], ':') !== false ? '[' . $server['host'] . ']' : $server['host'];

        return 'ss://' . $userinfo . '@' . $host . ':' . $server['port'] . '#' . rawurlencode((string)$node->name);
    }

    /**
     * Nodes available to the user under the same class/group policy as sshappy.
     *
     * @param User $user
     * @return mixed
     */
    public static function nodesForUser(User $user)
    {
        $query = Node::where('sort', self::NODE_SORT)
            ->where('type', 1)
            ->where(static function ($bandwidth) {
                $bandwidth->where('node_bandwidth_limit', 0)
                    ->orWhereRaw('node_bandwidth < node_bandwidth_limit');
            });

        if (!$user->is_admin) {
            $query->where('node_class', '<=', $user->class)
                ->where(static function ($group) use ($user) {
                    $group->where('node_group', 0)
                        ->orWhere('node_group', $user->node_group);
                });
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Return only the public endpoint portion of a sort=14 node.
     *
     * @param Node $node
     * @return string
     */
    public static function publicEndpoint(Node $node)
    {
        try {
            $server = self::parseServer($node->server);
            return $server['host'] . ':' . $server['port'];
        } catch (InvalidArgumentException $exception) {
            return 'SS2022 配置无效';
        }
    }

    /**
     * @param string $input
     * @return string
     */
    private static function base64UrlEncode($input)
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
