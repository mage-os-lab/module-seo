<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;

class OrganizationProvider implements StructuredDataProviderInterface
{
    /**
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly StoreManagerInterface           $storeManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['*'];
    }

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        $storeId   = (int) $this->storeManager->getStore()->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        $org       = $this->organisationRepository->getForScope($storeId, $websiteId);

        if ($org->getName() === '') {
            return [];
        }

        $baseUrl = rtrim($org->getUrl(), '/');
        $orgId   = $baseUrl . '/#organization';

        $orgSchema = [
            '@context' => 'https://schema.org',
            '@type'    => $org->getOrgType(),
            '@id'      => $orgId,
            'name'     => $org->getName(),
            'url'      => $baseUrl,
        ];

        // Logo
        if ($org->getLogoPath() !== '') {
            $logoNode = [
                '@type' => 'ImageObject',
                'url'   => $org->getLogoPath(),
            ];
            if ($org->getLogoWidth() > 0) {
                $logoNode['width'] = $org->getLogoWidth();
            }
            if ($org->getLogoHeight() > 0) {
                $logoNode['height'] = $org->getLogoHeight();
            }
            $orgSchema['logo'] = $logoNode;
        }

        if ($org->getDescription() !== '') {
            $orgSchema['description'] = $org->getDescription();
        }

        $socials = $org->getSocialProfiles();
        if (!empty($socials)) {
            $orgSchema['sameAs'] = array_values($socials);
        }

        $contact = $org->getContactPoint();
        if (!empty($contact)) {
            $orgSchema['contactPoint'] = array_merge(
                ['@type' => 'ContactPoint'],
                $contact
            );
        }

        // WebSite with SearchAction
        $websiteSchema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            '@id'             => $baseUrl . '/#website',
            'name'            => $org->getName(),
            'url'             => $baseUrl,
            'publisher'       => ['@id' => $orgId],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $baseUrl . '/catalogsearch/result?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];

        return [$orgSchema, $websiteSchema];
    }
}
