<?php
declare(strict_types=1);

namespace Infrastructure\Auth;

use Infrastructure\Auth\Schemes\ApiKeyScheme;
use Infrastructure\Auth\Schemes\HmacSharedSecretScheme;
use Infrastructure\Auth\Schemes\OAuthBearerScheme;

/**
 * Tries a request against a LIST of acceptable (scheme, context)
 * pairs and returns which one matched, or null. This is what
 * "please everyone" means concretely: a receiving endpoint declares
 * every counterparty+scheme it's willing to accept, and this checks
 * each until one verifies — rather than one file hardcoding one
 * scheme and rejecting everything else.
 */
final class AuthSchemeRegistry
{
    /** @var AuthSchemeInterface[] */
    private array $schemes;

    public function __construct()
    {
        $this->schemes = [
            'API_KEY' => new ApiKeyScheme(),
            'HMAC_SHARED_SECRET' => new HmacSharedSecretScheme(),
            'OAUTH_BEARER' => new OAuthBearerScheme(),
        ];
    }

    public function register(AuthSchemeInterface $scheme): void
    {
        $this->schemes[$scheme->name()] = $scheme;
    }

    /**
     * @param array $acceptedCredentials List of ['scheme' => 'API_KEY', 'label' => 'VOUCHMORPH', 'context' => [...]]
     * @return array{matched: bool, label: ?string, scheme: ?string}
     */
    public function verifyAny(array $headers, string $rawBody, array $acceptedCredentials): array
    {
        foreach ($acceptedCredentials as $credential) {
            $schemeName = $credential['scheme'];
            $scheme = $this->schemes[$schemeName] ?? null;
            if (!$scheme) continue;

            if ($scheme->verify($headers, $rawBody, $credential['context'])) {
                return ['matched' => true, 'label' => $credential['label'], 'scheme' => $schemeName];
            }
        }
        return ['matched' => false, 'label' => null, 'scheme' => null];
    }

    public function sign(string $schemeName, string $rawBody, array $context): array
    {
        $scheme = $this->schemes[$schemeName] ?? null;
        if (!$scheme) throw new \RuntimeException("Unknown auth scheme: {$schemeName}");
        return $scheme->sign($rawBody, $context);
    }
}
