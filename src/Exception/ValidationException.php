<?php

declare(strict_types=1);

namespace ZaSend\Exception;

/** HTTP 400, 413, 415 or 422: the request was refused as invalid (or a recipient is suppressed). */
class ValidationException extends ApiException
{
}
