<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class CashelaApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly mixed $raw)
    {
        parent::__construct($message);
    }
}
