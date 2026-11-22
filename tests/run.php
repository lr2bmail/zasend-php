<?php

declare(strict_types=1);

// Dependency-free tests: php tests/run.php (exit code 1 on failure).

require __DIR__ . '/../src/Exception/ZaSendException.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/ValidationException.php';
require __DIR__ . '/../src/ZaSend.php';
require __DIR__ . '/../src/Attachment.php';
require __DIR__ . '/../src/Webhook.php';

use ZaSend\Attachment;
use ZaSend\Exception\ApiException;
use ZaSend\Exception\RateLimitException;
use ZaSend\Exception\ValidationException;
use ZaSend\Exception\ZaSendException;
use ZaSend\Webhook;
use ZaSend\ZaSend;

$failures = 0;
$count = 0;

function check(bool $condition, string $label): void
{
    global $failures, $count;
    $count++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

/** A client whose transport records the request and returns a canned response. */
function fake(int $status, string $body, ?array &$seen): ZaSend
{
    return new ZaSend('sk_test', ['base_url' => 'https://api.test/v1/', 'transport' =>
        function (string $method, string $url, array $headers, ?string $payload, int $timeout) use ($status, $body, &$seen): array {
            $seen = compact('method', 'url', 'headers', 'payload', 'timeout');
            return [$status, $body];
        }]);
}

// send
$seen = null;
$result = fake(202, '{"success":true,"message_id":"m1@x","status":"queued"}', $seen)
    ->sendEmail(['from' => 'a@x.com', 'to' => 'b@y.com', 'subject' => 'Hi', 'text' => 'Yo', 'cc' => null], 'key-1');
check($result['message_id'] === 'm1@x', 'send returns decoded body');
check($seen['method'] === 'POST' && $seen['url'] === 'https://api.test/v1/emails/send', 'send url and method');
check($seen['headers']['Authorization'] === 'Bearer sk_test', 'bearer auth');
check($seen['headers']['Idempotency-Key'] === 'key-1', 'idempotency header');
check(!array_key_exists('cc', json_decode($seen['payload'], true)), 'nulls dropped from body');
check(str_starts_with($seen['headers']['User-Agent'], 'zasend-php/'), 'user agent');

// batch
$seen = null;
$result = fake(207, '{"success":false,"queued":1,"failed":1,"results":[{"index":0,"status":"queued"},{"index":1,"status":"error"}]}', $seen)
    ->sendBatch([['from' => 'a@x.com', 'to' => 'b@y.com'], ['from' => 'a@x.com', 'to' => 'c@y.com']]);
check($seen['url'] === 'https://api.test/v1/emails/batch', 'batch url');
check(count(json_decode($seen['payload'], true)['emails']) === 2, 'batch body');
check($result['failed'] === 1, 'partial batch is not an exception');

// template: empty variables must be a JSON object
$seen = null;
fake(202, '{"success":true}', $seen)->sendTemplateEmail('a@x.com', 'b@y.com', 'welcome');
check(str_contains($seen['payload'], '"variables":{}'), 'empty variables encode as an object');

// errors
$cases = [[429, RateLimitException::class], [400, ValidationException::class], [422, ValidationException::class], [500, ApiException::class]];
foreach ($cases as [$status, $class]) {
    $seen = null;
    try {
        fake($status, '{"error":"x_code","message":"Nope"}', $seen)->getRateLimits();
        check(false, "status $status throws");
    } catch (ApiException $e) {
        check(get_class($e) === $class, "status $status throws $class");
        check($e->getStatusCode() === $status && $e->getErrorCode() === 'x_code', "status $status details");
    }
}
try {
    new ZaSend('');
    check(false, 'empty key throws');
} catch (ZaSendException $e) {
    check(true, 'empty key throws');
}

// 204, binary, query string, path encoding
$seen = null;
check(fake(204, '', $seen)->deleteDomain('d/1') === null, 'delete returns null');
check($seen['url'] === 'https://api.test/v1/domains/d%2F1', 'ids are url-encoded');
$seen = null;
check(fake(200, "RAW\x00BYTES", $seen)->downloadInboundRaw('m1') === "RAW\x00BYTES", 'binary download returns bytes');
$seen = null;
fake(200, '{"suppressions":[]}', $seen)->listSuppressions(['page' => 2, 'reason' => null]);
check($seen['url'] === 'https://api.test/v1/suppressions?page=2', 'query string skips nulls');
$seen = null;
try {
    fake(200, 'not json', $seen)->listDomains();
    check(false, 'invalid JSON throws');
} catch (ApiException $e) {
    check(true, 'invalid JSON throws');
}

// attachments
$tmp = tempnam(sys_get_temp_dir(), 'zs');
file_put_contents($tmp, 'hello');
$att = Attachment::fromFile($tmp, 'hi.txt');
check($att === ['filename' => 'hi.txt', 'content' => base64_encode('hello')], 'attachment from file');
unlink($tmp);

// webhooks (same vectors as the server: t=1700000000, body {"event":"failed"}, secret s3cret)
$body = '{"event":"failed"}';
$v1 = hash_hmac('sha256', $body, 's3cret');
check(Webhook::verifySignature($body, 's3cret', 'sha256=' . $v1), 'v1 valid');
check(!Webhook::verifySignature($body, 'wrong', 'sha256=' . $v1), 'v1 wrong secret');
$v2 = 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $body, 's3cret');
check(Webhook::verifySignatureV2($body, 's3cret', $v2, 300, 1700000100), 'v2 valid');
check(!Webhook::verifySignatureV2($body, 's3cret', $v2, 300, 1700001000), 'v2 too old');
check(!Webhook::verifySignatureV2($body . ' ', 's3cret', $v2, 300, 1700000100), 'v2 body changed');
check(!Webhook::verifySignatureV2($body, 's3cret', 'garbage', 300, 1700000100), 'v2 malformed');

// SMTP passwords, API keys, inbox list
fake(200, '{"smtp_passwords":[]}', $seen)->listSmtpPasswords('acme.com');
check($seen['method'] === 'GET' && $seen['url'] === 'https://api.test/v1/smtp-passwords?domain=acme.com', 'list smtp passwords');
fake(201, '{"password":"smtp_live_x"}', $seen)->createSmtpPassword('acme.com', 'WordPress');
check($seen['url'] === 'https://api.test/v1/smtp-passwords' && json_decode($seen['payload'], true) === ['domain' => 'acme.com', 'name' => 'WordPress'], 'create smtp password');
fake(204, '', $seen)->deleteSmtpPassword('pw/1');
check($seen['method'] === 'DELETE' && $seen['url'] === 'https://api.test/v1/smtp-passwords/pw%2F1', 'delete smtp password');
fake(200, '{"api_keys":[]}', $seen)->listApiKeys();
check($seen['url'] === 'https://api.test/v1/api-keys', 'list api keys');
fake(201, '{"key":"zs_live_x"}', $seen)->createApiKey('Bot', 'agent', 'acme.com');
check(json_decode($seen['payload'], true) === ['name' => 'Bot', 'access' => 'agent', 'domain' => 'acme.com'], 'create api key');
fake(201, '{"key":"zs_live_y"}', $seen)->createApiKey('Plain');
check(json_decode($seen['payload'], true) === ['name' => 'Plain'], 'create api key defaults');
fake(204, '', $seen)->revokeApiKey('k1');
check($seen['url'] === 'https://api.test/v1/api-keys/k1', 'revoke api key');
fake(200, '{"messages":[]}', $seen)->listInboundMessages(['limit' => 5, 'unread' => true]);
check($seen['url'] === 'https://api.test/v1/inbound?limit=5&unread=1', 'list inbound');
try {
    fake(403, '{"error":"forbidden","message":"no inbound","missing_permission":"inbound"}', $seen)->listInboundMessages();
    check(false, 'missing permission throws');
} catch (ApiException $e) {
    check($e->getStatusCode() === 403 && $e->getMissingPermission() === 'inbound', 'missing permission on the exception');
}

echo ($failures === 0 ? 'OK' : 'FAILED') . " ($count checks, $failures failed)\n";
exit($failures === 0 ? 0 : 1);
