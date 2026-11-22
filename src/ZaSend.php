<?php

declare(strict_types=1);

namespace ZaSend;

use ZaSend\Exception\ApiException;
use ZaSend\Exception\RateLimitException;
use ZaSend\Exception\ValidationException;
use ZaSend\Exception\ZaSendException;

/**
 * Lightweight client for the zaSend API. Stays close to the raw API: methods
 * take and return plain arrays matching the JSON documented at
 * https://zasend.com/docs and https://zasend.com/api/v1/openapi.json.
 */
final class ZaSend
{
    public const DEFAULT_BASE_URL = 'https://zasend.com/api/v1';
    public const VERSION = '0.2.0';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    /** @var callable(string, string, array<string,string>, ?string, int): array{0:int,1:string} */
    private $transport;

    /**
     * @param array{base_url?: string, timeout?: int, transport?: callable} $options
     *        transport(method, url, headers, body, timeout) returns [status, body]; for tests or a custom HTTP client.
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new ZaSendException('API key is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (int) ($options['timeout'] ?? 10);
        $this->transport = $options['transport'] ?? [self::class, 'curlTransport'];
    }

    /**
     * Send an email. $message holds the fields of POST /emails/send: from, to, subject, html, text,
     * template, variables, cc, bcc, attachments (see Attachment::fromFile), list_unsubscribe.
     * With $idempotencyKey a retry within 24 hours returns the first result instead of sending again.
     */
    public function sendEmail(array $message, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails/send', $message, $this->idempotency($idempotencyKey));
    }

    /**
     * Up to 100 different emails in one call. Each item has the fields of sendEmail().
     * Returns ['success', 'queued', 'failed', 'results' => [['index', 'status', 'message_id'|'error', ...]]];
     * a partial failure is not an exception, check 'failed' and 'results'.
     */
    public function sendBatch(array $emails, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails/batch', ['emails' => array_values($emails)], $this->idempotency($idempotencyKey));
    }

    public function sendTemplateEmail(string $from, $to, string $template, array $variables = [], array $extra = []): array
    {
        return $this->sendEmail(array_merge($extra, [
            'from' => $from, 'to' => $to, 'template' => $template, 'variables' => (object) $variables,
        ]));
    }

    public function getEmail(string $messageId): array
    {
        return $this->request('GET', '/emails/' . rawurlencode($messageId));
    }

    public function getRateLimits(): array
    {
        return $this->request('GET', '/rate-limits');
    }

    public function listDomains(): array
    {
        return $this->request('GET', '/domains');
    }

    public function addDomain(string $domain): array
    {
        return $this->request('POST', '/domains', ['domain' => $domain]);
    }

    public function verifyDomain(string $domainId): array
    {
        return $this->request('POST', '/domains/' . rawurlencode($domainId) . '/verify');
    }

    public function deleteDomain(string $domainId): void
    {
        $this->request('DELETE', '/domains/' . rawurlencode($domainId));
    }

    /** @param array{page?: int, per_page?: int, reason?: string} $params */
    public function listSuppressions(array $params = []): array
    {
        $query = http_build_query(array_filter($params, static fn ($v) => $v !== null));
        return $this->request('GET', '/suppressions' . ($query !== '' ? '?' . $query : ''));
    }

    public function addSuppression(string $email, string $reason = 'manual', ?string $details = null): array
    {
        return $this->request('POST', '/suppressions', ['email' => $email, 'reason' => $reason, 'details' => $details]);
    }

    public function deleteSuppression(string $suppressionId): void
    {
        $this->request('DELETE', '/suppressions/' . rawurlencode($suppressionId));
    }

    public function listWebhooks(): array
    {
        return $this->request('GET', '/webhooks');
    }

    /** @param string[] $events */
    public function createWebhook(string $url, array $events): array
    {
        return $this->request('POST', '/webhooks', ['url' => $url, 'events' => array_values($events)]);
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->request('DELETE', '/webhooks/' . rawurlencode($webhookId));
    }

    public function listTemplates(): array
    {
        return $this->request('GET', '/templates');
    }

    /** @param array{name: string, slug: string, subject: string, html?: string, text?: string} $template */
    public function createTemplate(array $template): array
    {
        return $this->request('POST', '/templates', $template);
    }

    public function getTemplate(string $templateId): array
    {
        return $this->request('GET', '/templates/' . rawurlencode($templateId));
    }

    public function updateTemplate(string $templateId, array $template): array
    {
        return $this->request('PUT', '/templates/' . rawurlencode($templateId), $template);
    }

    public function deleteTemplate(string $templateId): void
    {
        $this->request('DELETE', '/templates/' . rawurlencode($templateId));
    }

    /**
     * Received messages, newest first, without bodies.
     * @param array{limit?: int, domain?: string, to?: string, unread?: bool} $params
     */
    public function listInboundMessages(array $params = []): array
    {
        if (array_key_exists('unread', $params)) {
            $params['unread'] = $params['unread'] ? '1' : null;
        }
        return $this->request('GET', '/inbound' . self::query($params));
    }

    /** SMTP passwords for SMTP apps (never the passwords themselves). */
    public function listSmtpPasswords(?string $domain = null): array
    {
        return $this->request('GET', '/smtp-passwords' . self::query(['domain' => $domain]));
    }

    /** A new SMTP password; it is in "password" and returned only once. Works over SMTP only. */
    public function createSmtpPassword(string $domain, ?string $name = null): array
    {
        return $this->request('POST', '/smtp-passwords', ['domain' => $domain, 'name' => $name]);
    }

    public function deleteSmtpPassword(string $passwordId): void
    {
        $this->request('DELETE', '/smtp-passwords/' . rawurlencode($passwordId));
    }

    /** Needs a Full access key. */
    public function listApiKeys(): array
    {
        return $this->request('GET', '/api-keys');
    }

    /**
     * @param string|string[]|null $access "sending", "agent", "full" (default) or a list of permissions
     * @param string|null $domain limit the key to one verified domain
     * The new key is in "key" and returned only once.
     */
    public function createApiKey(string $name, $access = null, ?string $domain = null): array
    {
        return $this->request('POST', '/api-keys', ['name' => $name, 'access' => $access, 'domain' => $domain]);
    }

    public function revokeApiKey(string $keyId): void
    {
        $this->request('DELETE', '/api-keys/' . rawurlencode($keyId));
    }

    /** A received message with its text, HTML and attachment list (no file content). */
    public function getInboundMessage(string $messageId): array
    {
        return $this->request('GET', '/inbound/' . rawurlencode($messageId));
    }

    /** The bytes of one attachment of a received message; $index is the attachment id. */
    public function downloadInboundAttachment(string $messageId, int $index): string
    {
        return $this->request('GET', '/inbound/' . rawurlencode($messageId) . '/attachments/' . $index, null, [], true);
    }

    /** The original received message (.eml) as bytes. */
    public function downloadInboundRaw(string $messageId): string
    {
        return $this->request('GET', '/inbound/' . rawurlencode($messageId) . '/raw', null, [], true);
    }

    private static function query(array $params): string
    {
        $query = http_build_query(array_filter($params, static fn ($v) => $v !== null));
        return $query !== '' ? '?' . $query : '';
    }

    /** @return array<string,string> */
    private function idempotency(?string $key): array
    {
        return $key !== null && $key !== '' ? ['Idempotency-Key' => $key] : [];
    }

    /**
     * @param array<string,string> $extraHeaders
     * @return mixed
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = [], bool $binary = false)
    {
        $headers = $extraHeaders + [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => $binary ? '*/*' : 'application/json',
            'User-Agent' => 'zasend-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode(self::dropNulls($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        [$status, $raw] = ($this->transport)($method, $this->baseUrl . $path, $headers, $payload, $this->timeout);

        if ($status === 204) {
            return null;
        }
        if ($binary && $status >= 200 && $status < 300) {
            return $raw;
        }
        $data = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new ApiException('API returned invalid JSON', $status);
            }
            return $data;
        }
        $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : 'HTTP ' . $status;
        if ($status === 429) {
            throw new RateLimitException($message, $status, $data);
        }
        if (in_array($status, [400, 413, 415, 422], true)) {
            throw new ValidationException($message, $status, $data);
        }
        throw new ApiException('API error ' . $status . ': ' . $message, $status, $data);
    }

    private static function dropNulls(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($item === null) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = self::dropNulls($item);
            }
        }
        return $value;
    }

    /**
     * Default HTTP transport (ext-curl).
     * @param array<string,string> $headers
     * @return array{0:int,1:string}
     */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ApiException('API request failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return [$status, (string) $raw];
    }
}
