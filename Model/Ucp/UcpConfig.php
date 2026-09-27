<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Configuration reader for the UCP / well-known subsystem.
 *
 * Most UCP values are website-scoped; reads use store scope so they resolve through the normal
 * default -> website -> store hierarchy for whichever store is active on the request.
 */
class UcpConfig
{
    public const XML_UCP_ENABLED           = 'mageos_seo_ucp/general/enabled';
    public const XML_UCP_MERCHANT_ID       = 'mageos_seo_ucp/general/merchant_id';
    public const XML_UCP_MERCHANT_NAME     = 'mageos_seo_ucp/general/merchant_name';
    public const XML_UCP_SIGNING_JWK       = 'mageos_seo_ucp/signing/public_key_jwk';
    public const XML_UCP_SIGNING_PRIVATE   = 'mageos_seo_ucp/signing/private_key';

    public const XML_AI_PLUGIN_ENABLED     = 'mageos_seo_ucp/ai_plugin/enabled';
    public const XML_AI_PLUGIN_DESCRIPTION = 'mageos_seo_ucp/ai_plugin/description';
    public const XML_AI_PLUGIN_LEGAL_URL   = 'mageos_seo_ucp/ai_plugin/legal_url';

    public const XML_SECURITY_TXT_ENABLED  = 'mageos_seo_ucp/security_txt/enabled';
    public const XML_SECURITY_TXT_CONTACT  = 'mageos_seo_ucp/security_txt/contact_email';
    public const XML_SECURITY_TXT_EXPIRES  = 'mageos_seo_ucp/security_txt/expires';
    public const XML_SECURITY_TXT_POLICY   = 'mageos_seo_ucp/security_txt/policy_url';

    /**
     * @var array<string, string>
     */
    private const CAPABILITY_PATHS = [
        'catalog'          => 'mageos_seo_ucp/capabilities/catalog',
        'cart'             => 'mageos_seo_ucp/capabilities/cart',
        'checkout'         => 'mageos_seo_ucp/capabilities/checkout',
        'identity_linking' => 'mageos_seo_ucp/capabilities/identity_linking',
        'order_management' => 'mageos_seo_ucp/capabilities/order_management',
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
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
     * Whether the ai-plugin.json manifest is enabled.
     *
     * @return bool
     */
    public function isAiPluginEnabled(): bool
    {
        return $this->flag(self::XML_AI_PLUGIN_ENABLED);
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
     * The configured (or auto-derived) merchant id in reverse-domain form.
     *
     * @return string
     */
    public function getMerchantId(): string
    {
        $configured = $this->value(self::XML_UCP_MERCHANT_ID);
        if ($configured !== '') {
            return $configured;
        }

        $host = $this->getDomainHost();
        if ($host === '') {
            return '';
        }

        // example.co.uk -> uk.co.example
        return implode('.', array_reverse(explode('.', $host)));
    }

    /**
     * The configured merchant name, falling back to the store/website frontend name.
     *
     * @return string
     */
    public function getMerchantName(): string
    {
        $configured = $this->value(self::XML_UCP_MERCHANT_NAME);
        if ($configured !== '') {
            return $configured;
        }

        return (string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * The store base URL (with trailing slash trimmed).
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
    }

    /**
     * The host portion of the store base URL.
     *
     * @return string
     */
    public function getDomainHost(): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return (string) parse_url($this->getBaseUrl(), PHP_URL_HOST);
    }

    /**
     * The enabled capability keys (subset of catalog/cart/checkout/identity_linking/order_management).
     *
     * @return string[]
     */
    public function getEnabledCapabilities(): array
    {
        $enabled = [];
        foreach (self::CAPABILITY_PATHS as $key => $path) {
            if ($this->flag($path)) {
                $enabled[] = $key;
            }
        }

        return $enabled;
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
     * Description used for the ai-plugin.json manifest.
     *
     * @return string
     */
    public function getAiPluginDescription(): string
    {
        return $this->value(self::XML_AI_PLUGIN_DESCRIPTION);
    }

    /**
     * Legal info URL used for the ai-plugin.json manifest.
     *
     * @return string
     */
    public function getAiPluginLegalUrl(): string
    {
        return $this->value(self::XML_AI_PLUGIN_LEGAL_URL);
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
