<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\StructuredData\OrganizationId;

class OrganizationProvider implements StructuredDataProviderInterface
{
    /**
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param StoreManagerInterface $storeManager
     * @param OrganizationId $organizationId
     * @param array<string,string> $localBusinessTypes The @type values that are LocalBusiness types
     *                                                 and so take geo and priceRange; di.xml sets
     *                                                 LocalBusiness, and a module that stores a
     *                                                 subtype (Store, Restaurant…) adds it there
     */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly StoreManagerInterface           $storeManager,
        private readonly OrganizationId                  $organizationId,
        private readonly array                           $localBusinessTypes = []
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
        $org       = $this->organizationRepository->getForScope($storeId, $websiteId);

        if ($org->getName() === '') {
            return [];
        }

        $baseUrl = rtrim($org->getUrl(), '/');
        $orgId   = $this->organizationId->fromUrl($org->getUrl());

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

        // Address, telephone and email on every type; geo and price range on a LocalBusiness type
        // only (@type comes from org_type).
        $orgSchema = $this->addLocalPresence($orgSchema, $org);

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

    /**
     * Append the local presence fields that are populated and valid on the node's type.
     *
     * Address, telephone and email are Organization properties, valid on every type. Geo and price
     * range are not: schema.org gives geo to Place and priceRange to LocalBusiness (which is both
     * an Organization and a Place), and Google points physical businesses to LocalBusiness
     * subtypes. So those two go on a type in $localBusinessTypes only.
     *
     * @param array<string,mixed> $orgSchema
     * @param \MageOS\Seo\Api\Data\OrganizationInterface $org
     * @return array<string, mixed>
     */
    private function addLocalPresence(array $orgSchema, \MageOS\Seo\Api\Data\OrganizationInterface $org): array
    {
        $address = array_filter($org->getAddress(), static fn (string $v): bool => $v !== '');
        if ($address !== []) {
            $postal = ['@type' => 'PostalAddress'];
            $map = [
                'streetAddress'   => 'street_address',
                'addressLocality' => 'address_locality',
                'addressRegion'   => 'address_region',
                'postalCode'      => 'postal_code',
                'addressCountry'  => 'address_country',
            ];
            foreach ($map as $schemaKey => $dataKey) {
                if (!empty($address[$dataKey])) {
                    $postal[$schemaKey] = $address[$dataKey];
                }
            }
            $orgSchema['address'] = $postal;
        }

        $isLocalBusiness = \in_array($org->getOrgType(), $this->localBusinessTypes, true);

        $latitude  = $org->getLatitude();
        $longitude = $org->getLongitude();
        if ($isLocalBusiness && $latitude !== '' && $longitude !== '') {
            $orgSchema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ];
        }

        if ($org->getTelephone() !== '') {
            $orgSchema['telephone'] = $org->getTelephone();
        }
        if ($org->getEmail() !== '') {
            $orgSchema['email'] = $org->getEmail();
        }
        if ($isLocalBusiness && $org->getPriceRange() !== '') {
            $orgSchema['priceRange'] = $org->getPriceRange();
        }

        return $orgSchema;
    }
}
