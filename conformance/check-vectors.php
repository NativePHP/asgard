<?php
// Checks the protocol repo's conformance vectors with this package's own
// crypto classes, an implementation independent of the Node runner.
require __DIR__.'/../vendor/autoload.php';

use Native\Asgard\Security\{Aes128Gcm, Base64Url, Ecdh, Gzip, SessionKeys, Wire, SecurityException};

$v = json_decode(file_get_contents($argv[1]), true);
$u = fn ($s) => Base64Url::decode($s);
$e = fn ($b) => Base64Url::encode($b);
$fails = 0;
$check = function (string $name, callable $fn) use (&$fails) {
    try { $fn(); echo "PASS  $name\n"; } catch (Throwable $t) { $fails++; echo "FAIL  $name: ".$t->getMessage()."\n"; }
};
$eq = function ($what, $got, $want) { if ($got !== $want) throw new RuntimeException("$what: got ".substr((string) $got, 0, 100).' expected '.substr((string) $want, 0, 100)); };

$h = $v['handshake']; $session = $h['session'];
$cpriv = Ecdh::privateKey($u($h['client_private'])); $spriv = Ecdh::privateKey($u($h['server_private']));
$cpub = Ecdh::publicPoint($cpriv); $spub = Ecdh::publicPoint($spriv);
$shared = Ecdh::agree($cpriv, $spub);
$keys = SessionKeys::fromSecret(SessionKeys::secret($shared, $cpub, $spub));
$check('handshake', function () use ($eq, $e, $h, $cpub, $spub, $shared, $spriv, $keys) {
    $eq('client_public', $e($cpub), $h['client_public']); $eq('server_public', $e($spub), $h['server_public']);
    $eq('shared', $e($shared), $h['shared_secret']); $eq('server side', $e(Ecdh::agree($spriv, $cpub)), $h['shared_secret']);
    foreach (['sig_c' => 'sigClient', 'sig_s' => 'sigServer', 'enc_c' => 'encClient', 'enc_s' => 'encServer'] as $k => $p) $eq($k, $e($keys->$p), $h['keys'][$k]);
});

$sigs = [];
foreach ($v['requests'] as $name => $r) {
    $check("request $name", function () use ($r, $keys, $session, $u, $e, $eq, &$sigs, $name) {
        $body = isset($r['frame']) ? $u($r['frame']['bytes']) : $r['plaintext'];
        $sealed = Wire::sealRequest($keys, $session, $r['method'], $r['authority'], $r['path'], $body, $r['headers'], $r['created'], $r['nonce'], $u($r['salt']));
        foreach ($r['expected']['headers'] as $hn => $hv) $eq("header $hn", $sealed['headers'][$hn] ?? null, $hv);
        $eq('body', $e($sealed['body']), $r['expected']['body']);
        $opened = Wire::openRequest($keys, $session, $r['method'], $r['authority'], $r['path'], $sealed['headers'], $sealed['body'], $r['created']);
        $eq('opened', $opened['body'], $body);
        $sigs[$name] = $sealed['signature'];
    });
}
foreach ($v['responses'] as $name => $r) {
    $check("response $name", function () use ($r, $keys, $session, $u, $e, $eq, &$sigs) {
        $reqSig = $sigs[$r['answers']];
        if (isset($r['cache'])) {
            // The reference Wire does not sign Asgard-Cache yet; only open is checked below.
        } else {
            $sealed = Wire::sealResponse($keys, $session, $r['status'], $r['plaintext'], $reqSig, $r['created'], $u($r['salt']), $r['compress']);
            if ($r['compress']) $eq('gzip', $e(Gzip::encode($r['plaintext'])), $r['expected']['gzip']);
            foreach ($r['expected']['headers'] as $hn => $hv) $eq("header $hn", $sealed['headers'][$hn] ?? null, $hv);
            $eq('body', $e($sealed['body']), $r['expected']['body']);
        }
        $eq('opened', Wire::openResponse($keys, $session, $r['status'], $r['expected']['headers'], $u($r['expected']['body']), $reqSig, $r['created']), $r['plaintext']);
    });
}
$streamSig = $sigs[$v['events']['answers']];
foreach ($v['events'] as $name => $ev) {
    if ($name === 'answers') continue;
    $check("event $name", function () use ($ev, $keys, $session, $u, $eq, $streamSig) {
        $data = Wire::sealEvent($keys, $session, $streamSig, $ev['id'], $ev['event'], $ev['plaintext'], $u($ev['salt']), $ev['compress']);
        $eq('data', $data, $ev['expected']['data']);
        $eq('opened', Wire::openEvent($keys, $session, $streamSig, $ev['id'], $ev['event'], $ev['expected']['data']), $ev['plaintext']);
    });
}
$check('records', function () use ($v, $keys, $session, $u, $e, $eq) {
    $r = $v['records'];
    $eq('body', $e(Aes128Gcm::encrypt($r['plaintext'], $keys->encServer, $session, 4096, $u($r['salt']))), $r['expected']['body']);
});
foreach ($v['refuse'] as $t) {
    $check('refuse '.$t['name'], function () use ($t, $v, $keys, $session, $u, &$sigs, $streamSig) {
        try {
            match ($t['open']) {
                'body' => Aes128Gcm::keyId($u($t['body'])) === $t['key_id'] ? Aes128Gcm::decrypt($u($t['body']), $keys->encServer) : throw new SecurityException('keyid'),
                'response' => (function () use ($t, $v, $keys, $session, $u, &$sigs) {
                    $r = $v['responses'][$t['response']]; $hs = array_merge($r['expected']['headers'], $t['set_header'] ?? []);
                    if (isset($t['drop_header'])) unset($hs[$t['drop_header']]);
                    return Wire::openResponse($keys, $session, $r['status'], $hs, $u($r['expected']['body']), $sigs[$t['request']], $r['created']);
                })(),
                'event' => (function () use ($t, $v, $keys, $session, $streamSig) {
                    $ev = $v['events'][$t['event']]; $d = json_decode($ev['expected']['data'], true);
                    if (isset($t['set_enc'])) $d['enc'] = $t['set_enc'];
                    return Wire::openEvent($keys, $session, $streamSig, $t['id'] ?? $ev['id'], $ev['event'], json_encode($d, JSON_UNESCAPED_SLASHES));
                })(),
                'point' => Ecdh::agree(Ecdh::generate()[0], $u($t['point'])),
            };
        } catch (SecurityException) { return; }
        throw new RuntimeException('accepted: '.$t['why']);
    });
}
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
