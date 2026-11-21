<?php

declare(strict_types=1);

namespace ZaSend;

use ZaSend\Exception\ZaSendException;

final class Attachment
{
    /** Read a file into the attachment format the API expects (base64 content). */
    public static function fromFile(string $path, ?string $filename = null): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new ZaSendException('Cannot read attachment: ' . $path);
        }
        return ['filename' => $filename ?? basename($path), 'content' => base64_encode($bytes)];
    }
}
