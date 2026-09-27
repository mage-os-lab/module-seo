<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Organisation;

use Magento\Framework\App\Config\Initial as InitialConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;

/**
 * The address the current store view publishes for automated queries.
 *
 * This class owns that choice. Its callers are /llms.txt and /llms-full.txt (Model\LlmsTxt\LlmsTxtBuilder,
 * the "AI Contact" section) and /.well-known/ai-plugin.json (Model\Ucp\AiPluginBuilder, `contact_email`).
 *
 * In order:
 * 1. the Organisation's Contact Email — the address this module publishes as the Organization's
 *    contactPoint, so the documents and the structured data agree;
 * 2. the store's Customer Support email (Stores → Configuration → General → Store Email Addresses),
 *    unless it is still the value Magento ships. Magento_Email's config.xml gives every store
 *    `support@example.com`, so an unconfigured store would otherwise publish a placeholder. The
 *    shipped value is read from the merged config.xml defaults rather than written here;
 * 3. none: ''.
 */
class ContactEmail
{
    private const XML_SUPPORT_EMAIL = 'trans_email/ident_support/email';

    /**
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param InitialConfig $initialConfig
     */
    public function __construct(
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly StoreManagerInterface           $storeManager,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly InitialConfig                   $initialConfig
    ) {
    }

    /**
     * The contact email for the current store view, or '' when it has none.
     *
     * @return string
     */
    public function get(): string
    {
        $storeId   = (int) $this->storeManager->getStore()->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();

        $contactPoint = $this->organisationRepository->getForScope($storeId, $websiteId)->getContactPoint();
        $organisation = trim((string) ($contactPoint['email'] ?? ''));
        if ($organisation !== '') {
            return $organisation;
        }

        $support = trim((string) $this->scopeConfig->getValue(
            self::XML_SUPPORT_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return $support === $this->shippedSupportEmail() ? '' : $support;
    }

    /**
     * The support email the installed modules' config.xml files ship, or '' when none does.
     *
     * @return string
     */
    private function shippedSupportEmail(): string
    {
        $defaults = $this->initialConfig->getData(ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        return trim((string) ($defaults['trans_email']['ident_support']['email'] ?? ''));
    }
}
