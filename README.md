# zasend-php

Official PHP client for the [zaSend](https://zasend.com) transactional email API.

Small and close to the raw API: methods take and return plain arrays. No dependencies beyond the `curl` and `json` extensions. PHP 8.1+.

## Install

```bash
composer require zasend/zasend-php
```

## Send an email

```php
use ZaSend\ZaSend;

$client = new ZaSend(getenv('ZASEND_API_KEY'));

$result = $client->sendEmail([
    'from' => 'Acme <noreply@yourdomain.com>',
    'to' => 'user@example.com',
    'subject' => 'Welcome',
    'html' => '<p>Thanks for signing up.</p>',
]);

echo $result['message_id'];
```

A domain key (the SMTP password from the domain page) works too, for sending only.

## Templates, attachments, retries

```php
use ZaSend\Attachment;

$client->sendTemplateEmail('noreply@yourdomain.com', 'user@example.com', 'welcome', ['name' => 'Ann']);

$client->sendEmail([
    'from' => 'billing@yourdomain.com',
    'to' => 'user@example.com',
    'subject' => 'Your invoice',
    'text' => 'Attached.',
    'attachments' => [Attachment::fromFile('/path/invoice.pdf')],
], idempotencyKey: 'invoice-1042');  // a retry within 24 hours does not send twice
```

## Batch: up to 100 different emails in one call

```php
$result = $client->sendBatch([
    ['from' => 'noreply@yourdomain.com', 'to' => 'ann@example.com', 'template' => 'welcome', 'variables' => ['name' => 'Ann']],
    ['from' => 'noreply@yourdomain.com', 'to' => 'bob@example.com', 'subject' => 'Receipt', 'text' => 'Thanks, Bob'],
]);

foreach ($result['results'] as $item) {
    echo $item['index'], ': ', $item['status'], ' ', $item['message_id'] ?? $item['message'], PHP_EOL;
}
```

A partial failure is not an exception: check `failed` and each item's `status`.

## Errors

| Exception | When |
|---|---|
| `ZaSend\Exception\RateLimitException` | 429: daily, monthly or per-minute limit |
| `ZaSend\Exception\ValidationException` | 400, 413, 415, 422: invalid request or a suppressed recipient |
| `ZaSend\Exception\ApiException` | any other error, or the API could not be reached |

All have `getStatusCode()`, `getErrorCode()` (for example `rate_limited`) and `getBody()`. When the API key lacks access, a 403 `ApiException` has `getMissingPermission()` (for example `inbound`).

## Which key do I need?

Create API keys on the **API keys** page of the dashboard. Each key has an access level and can be limited to one domain:

| Access | Use it for |
|---|---|
| Sending only | Apps that only send: `sendEmail`, `sendBatch`, `getEmail`, `getRateLimits` |
| Agent | Sending plus reading received email (`listInboundMessages`, `getInboundMessage`) |
| Full access | Everything, including domains, templates, webhooks, SMTP passwords and API keys |
| Custom | Pick permissions: send, status, inbound, smtp, templates, suppressions, webhooks, domains |

SMTP passwords (`smtp_live_...`) are for SMTP apps and don't work with this SDK; older domain keys (`dsk_live_...`) can still send.

## Webhooks

```php
use ZaSend\Webhook;

$body = file_get_contents('php://input');
$ok = Webhook::verifySignatureV2($body, $secret, $_SERVER['HTTP_X_ZASEND_SIGNATURE_V2'] ?? '');
if (!$ok) {
    http_response_code(400);
    exit;
}
$event = json_decode($body, true);  // "accepted_by_postfix", "bounced", "complained", "inbound.received", ...
```

`verifySignatureV2` also rejects deliveries older than 5 minutes. `Webhook::verifySignature` checks the older `X-zaSend-Signature` header.

## Everything else

| Method | API |
|---|---|
| `getEmail($messageId)` | `GET /emails/{id}` |
| `getRateLimits()` | `GET /rate-limits` (today and this month) |
| `listDomains()`, `addDomain()`, `verifyDomain()`, `deleteDomain()` | `/domains` |
| `listSuppressions()`, `addSuppression()`, `deleteSuppression()` | `/suppressions` |
| `listWebhooks()`, `createWebhook()`, `deleteWebhook()` | `/webhooks` |
| `listTemplates()`, `createTemplate()`, `getTemplate()`, `updateTemplate()`, `deleteTemplate()` | `/templates` |
| `listInboundMessages()`, `getInboundMessage()`, `downloadInboundAttachment()`, `downloadInboundRaw()` | `/inbound` |
| `listSmtpPasswords()`, `createSmtpPassword($domain, $name)`, `deleteSmtpPassword()` | `/smtp-passwords` (the new password is returned once) |
| `listApiKeys()`, `createApiKey($name, $access, $domain)`, `revokeApiKey()` | `/api-keys` (needs Full access) |

Full API: [zasend.com/docs](https://zasend.com/docs) · OpenAPI: [zasend.com/api/v1/openapi.json](https://zasend.com/api/v1/openapi.json)

## Tests

```bash
php tests/run.php
```
