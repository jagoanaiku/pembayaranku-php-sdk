<?php

declare(strict_types=1);

namespace PembayaranKu;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $apiCode,
        public readonly ?string $retryAfter = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
