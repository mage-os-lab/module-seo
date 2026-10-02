<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Data;

use Magento\Framework\Api\ExtensibleDataInterface;

/**
 * The Organization's settings for one scope (default, a website or a store view): the identity
 * published in structured data, Open Graph tags and the llms documents.
 *
 * Extensible: other modules add fields through extension_attributes.xml.
 *
 * @api
 */
interface OrganizationInterface extends ExtensibleDataInterface
{
    public const ENTITY_ID       = 'entity_id';
    public const SCOPE           = 'scope';
    public const SCOPE_ID        = 'scope_id';
    public const NAME            = 'name';
    public const URL             = 'url';
    public const LOGO_PATH       = 'logo_path';
    public const LOGO_WIDTH      = 'logo_width';
    public const LOGO_HEIGHT     = 'logo_height';
    public const DESCRIPTION     = 'description';
    public const SOCIAL_PROFILES = 'social_profiles';
    public const CONTACT_POINT   = 'contact_point';
    public const ORG_TYPE         = 'org_type';
    public const STREET_ADDRESS   = 'street_address';
    public const ADDRESS_LOCALITY = 'address_locality';
    public const ADDRESS_REGION   = 'address_region';
    public const POSTAL_CODE      = 'postal_code';
    public const ADDRESS_COUNTRY  = 'address_country';
    public const TELEPHONE        = 'telephone';
    public const EMAIL            = 'email';
    public const LATITUDE         = 'latitude';
    public const LONGITUDE        = 'longitude';
    public const PRICE_RANGE      = 'price_range';
    public const UPDATED_AT       = 'updated_at';

    /**
     * Get entity ID.
     *
     * @return int
     */
    public function getEntityId(): int;

    /**
     * Get config scope (default / websites / stores).
     *
     * @return string
     */
    public function getScope(): string;

    /**
     * Set config scope.
     *
     * @param string $scope
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setScope(string $scope): OrganizationInterface;

    /**
     * Get scope entity ID (0 for default scope).
     *
     * @return int
     */
    public function getScopeId(): int;

    /**
     * Set scope entity ID.
     *
     * @param int $scopeId
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setScopeId(int $scopeId): OrganizationInterface;

    /**
     * Get organization name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set organization name.
     *
     * @param string $name
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setName(string $name): OrganizationInterface;

    /**
     * Get canonical organization URL.
     *
     * @return string
     */
    public function getUrl(): string;

    /**
     * Set canonical organization URL.
     *
     * @param string $url
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setUrl(string $url): OrganizationInterface;

    /**
     * Get logo image path.
     *
     * @return string
     */
    public function getLogoPath(): string;

    /**
     * Set logo image path.
     *
     * @param string $logoPath
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setLogoPath(string $logoPath): OrganizationInterface;

    /**
     * Get logo width in pixels.
     *
     * @return int
     */
    public function getLogoWidth(): int;

    /**
     * Set logo width in pixels.
     *
     * @param int $width
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setLogoWidth(int $width): OrganizationInterface;

    /**
     * Get logo height in pixels.
     *
     * @return int
     */
    public function getLogoHeight(): int;

    /**
     * Set logo height in pixels.
     *
     * @param int $height
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setLogoHeight(int $height): OrganizationInterface;

    /**
     * Get organization description or tagline.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Set organization description or tagline.
     *
     * @param string $description
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setDescription(string $description): OrganizationInterface;

    /**
     * Decoded array of social profile URLs.
     *
     * @return string[]
     */
    public function getSocialProfiles(): array;

    /**
     * Set social profile URLs.
     *
     * @param string[] $profiles
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setSocialProfiles(array $profiles): OrganizationInterface;

    /**
     * Decoded contact point array: contactType, email, availableLanguage.
     *
     * @return mixed[]
     */
    public function getContactPoint(): array;

    /**
     * Set contact point data.
     *
     * @param mixed[] $contactPoint
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setContactPoint(array $contactPoint): OrganizationInterface;

    /**
     * Schema.org organization type: Organization, NGO, Corporation, etc.
     *
     * @return string
     */
    public function getOrgType(): string;

    /**
     * Set schema.org organization type.
     *
     * @param string $type
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setOrgType(string $type): OrganizationInterface;

    /**
     * Decoded LocalBusiness address: street, locality, region, postcode, country (any may be empty).
     *
     * @return array<string, string>
     */
    public function getAddress(): array;

    /**
     * Set LocalBusiness presence fields from a keyed array.
     *
     * Recognised keys: street_address, address_locality, address_region, postal_code,
     * address_country, telephone, email, latitude, longitude, price_range. Only present keys are set.
     *
     * @param array<string,string> $data
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setLocalPresence(array $data): OrganizationInterface;

    /**
     * Geo latitude, or empty string if unset.
     *
     * @return string
     */
    public function getLatitude(): string;

    /**
     * Geo longitude, or empty string if unset.
     *
     * @return string
     */
    public function getLongitude(): string;

    /**
     * Contact telephone, or empty string.
     *
     * @return string
     */
    public function getTelephone(): string;

    /**
     * Contact email, or empty string.
     *
     * @return string
     */
    public function getEmail(): string;

    /**
     * Price range indicator (e.g. ££), or empty string.
     *
     * @return string
     */
    public function getPriceRange(): string;

    /**
     * The fields other modules add through extension_attributes.xml.
     *
     * @return \MageOS\Seo\Api\Data\OrganizationExtensionInterface|null
     */
    public function getExtensionAttributes(): ?OrganizationExtensionInterface;

    /**
     * Set the fields other modules add.
     *
     * @param \MageOS\Seo\Api\Data\OrganizationExtensionInterface $extensionAttributes
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function setExtensionAttributes(OrganizationExtensionInterface $extensionAttributes): OrganizationInterface;
}
