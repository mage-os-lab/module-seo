<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;

/**
 * Single source of the Organization schema @id.
 *
 * AEO relies on every schema node (Organization, WebSite, LocalBusiness, Event, BlogPosting, …)
 * referencing the same Organization entity by @id, so an answer engine that resolves the
 * Organization once trusts all nodes that link to it. This service is the one place that formats
 * that id ({orgBaseUrl}/#organization), so providers never construct it independently.
 */
class OrganizationId
{
    /**
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly StoreManagerInterface           $storeManager
    ) {
    }

    /**
     * Format the Organization @id from an organization base URL.
     *
     * @param string $url
     * @return string
     */
    public function fromUrl(string $url): string
    {
        return rtrim($url, '/') . '/#organization';
    }

    /**
     * Whether the Organization is published: once it has a name.
     *
     * OrganizationProvider writes no node without one.
     *
     * @param OrganizationInterface $organization
     * @return bool
     */
    public function isPublished(OrganizationInterface $organization): bool
    {
        return $organization->getName() !== '';
    }

    /**
     * The published Organization's @id for a scope, or null while none is published there.
     *
     * The scope is resolved through the repository's fallback chain. A node that references the
     * Organization (an article's author and publisher, an event's organizer) leaves the reference
     * out on null: no node on the page would carry the @id, and with no URL configured it was the
     * bare "/#organization".
     *
     * @param int|null $storeId
     * @param int|null $websiteId
     * @return string|null
     */
    public function getPublishedId(?int $storeId = null, ?int $websiteId = null): ?string
    {
        $resolvedStoreId   = $storeId ?? (int) $this->storeManager->getStore()->getId();
        $resolvedWebsiteId = $websiteId ?? (int) $this->storeManager->getWebsite()->getId();
        $org               = $this->organizationRepository->getForScope($resolvedStoreId, $resolvedWebsiteId);

        return $this->isPublished($org) ? $this->fromUrl($org->getUrl()) : null;
    }
}
