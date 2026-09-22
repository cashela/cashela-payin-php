<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class WebhookSignatureError extends \RuntimeException
{
    /** @param 'missing-header'|'bad-signature'|'stale-timestamp' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("webhook signature invalid: {$reason}");
    }
}
