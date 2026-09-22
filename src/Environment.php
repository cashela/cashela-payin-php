<?php
declare(strict_types=1);
namespace Cashela\PayIn;

final class Environment
{
    private const ENVS = ['dev', 'staging', 'sandbox', 'production'];

    public static function resolveBaseUrl(string $env, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        if (!in_array($env, self::ENVS, true)) {
            throw new \InvalidArgumentException("unknown environment: {$env}");
        }
        return "https://{$env}-api.cashela.com/api/v1/pay-in";
    }
}
