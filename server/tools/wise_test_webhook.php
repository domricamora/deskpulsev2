<?php
// Fire a correctly-signed test delivery at /webhooks/wise.
//
// TESTING ONLY. The real webhook public key belongs to Wise — they sign deliveries
// with their private key and publish the public half. This script makes a THROWAWAY
// keypair so you can prove the endpoint verifies, dedupes and records correctly
// before Wise is connected. Paste the printed key into Accounting → Webhook public
// key, run the test, then REPLACE it with Wise's real key or live deliveries will
// all be rejected.
//
// Usage (from the repo root):
//   php server/tools/wise_test_webhook.php                       # print key + curl
//   php server/tools/wise_test_webhook.php --send=<base-url>     # sign and POST it
//   php server/tools/wise_test_webhook.php --send=<base-url> --amount=49.00
//   php server/tools/wise_test_webhook.php --tamper               # prove it rejects
//
// Example: php server/tools/wise_test_webhook.php --send=http://localhost/vt-deskpulse/server/public

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$amount = (float) ($opts['amount'] ?? 49.00);
$keyDir = sys_get_temp_dir() . '/deskpulse-wise-test';
@mkdir($keyDir, 0700, true);
$privPath = $keyDir . '/test.key';
$pubPath  = $keyDir . '/test.pub';

/** Make (or reuse) a throwaway RSA keypair. PHP's openssl_pkey_new() needs an
 *  openssl.cnf that many Windows CLI builds lack, so fall back to the binary. */
function make_keypair(string $priv, string $pub): bool
{
    if (is_file($priv) && is_file($pub)) {
        return true;
    }
    $k = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($k) {
        openssl_pkey_export($k, $pem);
        file_put_contents($priv, $pem);
        file_put_contents($pub, openssl_pkey_get_details($k)['key']);
        return true;
    }
    // Fall back to the openssl command-line tool.
    @exec('openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ' . escapeshellarg($priv) . ' 2>&1', $o, $rc);
    if ($rc !== 0 || !is_file($priv)) {
        return false;
    }
    @exec('openssl rsa -in ' . escapeshellarg($priv) . ' -pubout -out ' . escapeshellarg($pub) . ' 2>&1', $o2, $rc2);
    return $rc2 === 0 && is_file($pub);
}

if (!make_keypair($privPath, $pubPath)) {
    fwrite(STDERR, "Could not generate a test keypair.\n"
        . "PHP's openssl_pkey_new() failed (usually a missing openssl.cnf) and the\n"
        . "`openssl` command was not usable either. Install/expose openssl, or set\n"
        . "OPENSSL_CONF to a valid openssl.cnf, then re-run.\n");
    exit(1);
}

$publicPem = trim((string) file_get_contents($pubPath));
$privPem   = (string) file_get_contents($privPath);

// A realistic balances#credit body (delivery version 2.0.0).
$body = json_encode([
    'data' => [
        'resource' => ['id' => 999001, 'profile_id' => 123456, 'type' => 'balance-account'],
        'amount' => $amount,
        'currency' => 'USD',
        'transaction_type' => 'credit',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'post_transaction_balance_amount' => $amount,
    ],
    'subscription_id' => 'test-subscription',
    'event_type' => 'balances#credit',
    'schema_version' => '2.0.0',
    'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_UNESCAPED_SLASHES);

if (!empty($opts['tamper'])) {
    // Sign the original, then send a different body — the endpoint must return 401.
    $signedBody = $body;
    $body = str_replace('"amount":' . $amount, '"amount":' . ($amount * 100), $body);
} else {
    $signedBody = $body;
}
openssl_sign($signedBody, $sigRaw, $privPem, OPENSSL_ALGO_SHA256);
$sig = base64_encode($sigRaw);
$deliveryId = 'test-' . bin2hex(random_bytes(6));

echo "── Throwaway public key (paste into Accounting → Webhook public key) ──\n\n";
echo $publicPem . "\n\n";
echo "keys kept in: $keyDir\n";
echo str_repeat('─', 72) . "\n";

if (empty($opts['send'])) {
    $tmp = $keyDir . '/body.json';
    file_put_contents($tmp, $body);
    echo "Body written to: $tmp\n\n";
    echo "Run this to deliver it:\n\n";
    echo "curl -i -X POST '<your-base-url>/webhooks/wise' \\\n"
       . "  -H 'Content-Type: application/json' \\\n"
       . "  -H 'X-Signature-SHA256: $sig' \\\n"
       . "  -H 'X-Delivery-Id: $deliveryId' \\\n"
       . "  --data-binary @" . $tmp . "\n\n";
    echo "Or re-run with --send=<base-url> to POST it directly.\n";
    exit(0);
}

$url = rtrim((string) $opts['send'], '/') . '/webhooks/wise';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Signature-SHA256: ' . $sig,
        'X-Delivery-Id: ' . $deliveryId,
    ],
    CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "POST $url\n";
echo "  amount      : " . number_format($amount, 2) . " USD\n";
echo "  delivery id : $deliveryId\n";
echo "  tampered    : " . (!empty($opts['tamper']) ? 'yes (expect HTTP 401)' : 'no (expect HTTP 200)') . "\n";
echo "  → HTTP $code" . ($err ? "  curl error: $err" : '') . "\n";
echo "  → " . trim((string) $resp) . "\n\n";

$expected = !empty($opts['tamper']) ? 401 : 200;
if ($code === $expected) {
    echo "PASS — endpoint behaved as expected.\n";
    if (empty($opts['tamper'])) {
        echo "Now re-run with --tamper to confirm it rejects a modified body.\n";
    }
    echo "\nRemember: replace this throwaway key with Wise's real public key before going live.\n";
    exit(0);
}
echo "FAIL — expected HTTP $expected.\n";
echo "If you got 401 on a valid delivery, the key stored in DeskPulse is not the one\n"
   . "printed above. If you got 200 on a tampered body, no key is stored at all\n"
   . "(unverified mode).\n";
exit(1);
