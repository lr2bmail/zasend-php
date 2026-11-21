<?php

require __DIR__ . '/../vendor/autoload.php';

use ZaSend\Exception\RateLimitException;
use ZaSend\Exception\ValidationException;
use ZaSend\ZaSend;

$client = new ZaSend(getenv('ZASEND_API_KEY'));

try {
    $result = $client->sendEmail([
        'from' => 'Acme <noreply@yourdomain.com>',
        'to' => 'user@example.com',
        'subject' => 'Welcome',
        'text' => 'Thanks for signing up.',
    ]);
    echo $result['message_id'], PHP_EOL;
} catch (RateLimitException $e) {
    echo 'Limit reached: ', $e->getMessage(), PHP_EOL;
} catch (ValidationException $e) {
    echo 'Refused: ', $e->getMessage(), PHP_EOL;
}
