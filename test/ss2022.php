<?php

namespace Illuminate\Database\Eloquent {
    class Model
    {
        protected $attributes = array();

        public function __get($key)
        {
            return isset($this->attributes[$key]) ? $this->attributes[$key] : null;
        }

        public function __set($key, $value)
        {
            $this->attributes[$key] = $value;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/app/Models/Model.php';
    require dirname(__DIR__) . '/app/Models/User.php';
    require dirname(__DIR__) . '/app/Models/Node.php';
    require dirname(__DIR__) . '/app/Services/SS2022.php';
    require dirname(__DIR__) . '/app/Services/ClientConfig.php';

    use App\Models\Node;
    use App\Models\User;
    use App\Services\SS2022;
    use App\Services\ClientConfig;

    $serverKey = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
    $user = new User();
    $user->id = 42;
    $user->passwd = 'test-passwd';

    $node = new Node();
    $node->id = 7;
    $node->name = 'SS2022 Test';
    $node->server = '2001:db8::1;443;' . $serverKey;

    $parsed = SS2022::parseServer($node->server);
    assertSame('2001:db8::1', $parsed['host'], 'host parse');
    assertSame(443, $parsed['port'], 'port parse');
    assertSame(
        'Zw1Blz9Sc+KX2GjP05gy2utRr46eJzMXvLNLkzCscVw=',
        SS2022::deriveUserKey($user, $node),
        'sshappy-compatible user key'
    );

    $proxy = SS2022::mihomoProxy($user, $node);
    assertSame(SS2022::METHOD, $proxy['cipher'], 'mihomo cipher');
    assertSame($serverKey . ':Zw1Blz9Sc+KX2GjP05gy2utRr46eJzMXvLNLkzCscVw=', $proxy['password'], 'mihomo password');

    $link = SS2022::sip002Link($user, $node);
    if (strpos($link, '@[2001:db8::1]:443#SS2022%20Test') === false) {
        fail('SIP002 IPv6 endpoint');
    }

    $invalidRejected = false;
    try {
        SS2022::parseServer('example.com;443;not-a-key');
    } catch (\InvalidArgumentException $exception) {
        $invalidRejected = true;
    }
    assertSame(true, $invalidRejected, 'invalid server key rejection');

    $invalidHostRejected = false;
    try {
        SS2022::parseServer('example.com@attacker.test;443;' . $serverKey);
    } catch (\InvalidArgumentException $exception) {
        $invalidHostRejected = true;
    }
    assertSame(true, $invalidHostRejected, 'invalid host rejection');

    $mobileConfig = ClientConfig::renderMihomo(array($proxy), 'ios');
    if (strpos($mobileConfig, 'external-controller') !== false || strpos($mobileConfig, 'allow-lan: false') === false) {
        fail('safe mobile mihomo defaults');
    }
    if (strpos($mobileConfig, 'mixed-port:') !== false) {
        fail('mobile config must not create a local listener');
    }
    $desktopConfig = ClientConfig::renderMihomo(array($proxy), 'windows');
    if (strpos($desktopConfig, 'mixed-port: 7890') === false) {
        fail('desktop mihomo listener preset');
    }

    echo "SS2022 tests passed\n";

    function assertSame($expected, $actual, $label)
    {
        if ($expected !== $actual) {
            fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function fail($message)
    {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}
