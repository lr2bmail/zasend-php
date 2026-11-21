<?php

declare(strict_types=1);

namespace ZaSend\Exception;

/** HTTP 429: the daily, monthly or per-minute limit was reached. */
class RateLimitException extends ApiException
{
}
