<?php

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * An expected, client-facing error with a stable error code.
 * The message is a translation key under lang/{locale}/errors.php unless $rawMessage is given.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $details = [],
        ?string $rawMessage = null,
    ) {
        parent::__construct($rawMessage ?? '');
    }

    public function localizedMessage(): string
    {
        if ($this->getMessage() !== '') {
            return $this->getMessage();
        }

        $key = 'errors.'.strtolower($this->errorCode);
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : (string) __('errors.generic');
    }
}
