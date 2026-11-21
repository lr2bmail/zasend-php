<?php

declare(strict_types=1);

namespace ZaSend\Exception;

/** The API returned an error response (or could not be reached). */
class ApiException extends ZaSendException
{
    private ?int $statusCode;
    /** @var mixed */
    private $body;

    /** @param mixed $body decoded JSON error body, when there is one */
    public function __construct(string $message, ?int $statusCode = null, $body = null)
    {
        parent::__construct($message, $statusCode ?? 0);
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /** @return mixed */
    public function getBody()
    {
        return $this->body;
    }

    /** The API's error code, for example "rate_limited" or "suppressed". */
    public function getErrorCode(): ?string
    {
        return is_array($this->body) && isset($this->body['error']) ? (string) $this->body['error'] : null;
    }
}
