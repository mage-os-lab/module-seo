<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration reader for the UCP / well-known subsystem.
 *
 * Most UCP values are website-scoped; reads use store scope so they resolve through the normal
 * default -> website -> store hierarchy for whichever store is active on the request.
 */
class UcpConfig
{
    public const XML_UCP_ENABLED           = 'mageos_seo_ucp/general/enabled';
    public const XML_UCP_SIGNING_JWK       = 'mageos_seo_ucp/signing/public_key_jwk';
    public const XML_UCP_SIGNING_PRIVATE   = 'mageos_seo_ucp/signing/private_key';

    public const XML_SECURITY_TXT_ENABLED  = 'mageos_seo_ucp/security_txt/enabled';
    public const XML_SECURITY_TXT_CONTACT  = 'mageos_seo_ucp/security_txt/contact_email';
    public const XML_SECURITY_TXT_EXPIRES  = 'mageos_seo_ucp/security_txt/expires';
    public const XML_SECURITY_TXT_POLICY   = 'mageos_seo_ucp/security_txt/policy_url';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the UCP profile is enabled.
     *
     * @return bool
     */
    public function isUcpEnabled(): bool
    {
        return $this->flag(self::XML_UCP_ENABLED);
    }

    /**
     * Whether security.txt is enabled.
     *
     * @return bool
     */
    public function isSecurityTxtEnabled(): bool
    {
        return $this->flag(self::XML_SECURITY_TXT_ENABLED);
    }

    /**
     * The stored public signing key JWK JSON, or empty string when keygen has not been run.
     *
     * @return string
     */
    public function getPublicKeyJwk(): string
    {
        return $this->value(self::XML_UCP_SIGNING_JWK);
    }

    /**
     * Security contact email for security.txt.
     *
     * @return string
     */
    public function getSecurityContactEmail(): string
    {
        return $this->value(self::XML_SECURITY_TXT_CONTACT);
    }

    /**
     * Security policy expiry (ISO 8601) for security.txt.
     *
     * @return string
     */
    public function getSecurityExpires(): string
    {
        return $this->value(self::XML_SECURITY_TXT_EXPIRES);
    }

    /**
     * Security policy URL for security.txt.
     *
     * @return string
     */
    public function getSecurityPolicyUrl(): string
    {
        return $this->value(self::XML_SECURITY_TXT_POLICY);
    }

    /**
     * Read a string config value at store scope.
     *
     * @param string $path
     * @return string
     */
    private function value(string $path): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE));
    }

    /**
     * Read a boolean config flag at store scope.
     *
     * @param string $path
     * @return bool
     */
    private function flag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }
}
