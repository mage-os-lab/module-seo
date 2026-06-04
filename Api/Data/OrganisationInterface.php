<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Data;

interface OrganisationInterface
{
    public const ENTITY_ID       = 'entity_id';
    public const NAME            = 'name';
    public const URL             = 'url';
    public const LOGO_PATH       = 'logo_path';
    public const LOGO_WIDTH      = 'logo_width';
    public const LOGO_HEIGHT     = 'logo_height';
    public const DESCRIPTION     = 'description';
    public const SOCIAL_PROFILES = 'social_profiles';
    public const CONTACT_POINT   = 'contact_point';
    public const ORG_TYPE        = 'org_type';
    public const UPDATED_AT      = 'updated_at';

    /**
     * Get entity ID.
     *
     * @return int
     */
    public function getEntityId(): int;

    /**
     * Get organisation name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Set organisation name.
     *
     * @param string $name
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setName(string $name): OrganisationInterface;

    /**
     * Get canonical organisation URL.
     *
     * @return string
     */
    public function getUrl(): string;

    /**
     * Set canonical organisation URL.
     *
     * @param string $url
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setUrl(string $url): OrganisationInterface;

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
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setLogoPath(string $logoPath): OrganisationInterface;

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
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setLogoWidth(int $width): OrganisationInterface;

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
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setLogoHeight(int $height): OrganisationInterface;

    /**
     * Get organisation description or tagline.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Set organisation description or tagline.
     *
     * @param string $description
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setDescription(string $description): OrganisationInterface;

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
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setSocialProfiles(array $profiles): OrganisationInterface;

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
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setContactPoint(array $contactPoint): OrganisationInterface;

    /**
     * Schema.org organisation type: Organization, NGO, Corporation, etc.
     *
     * @return string
     */
    public function getOrgType(): string;

    /**
     * Set schema.org organisation type.
     *
     * @param string $type
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function setOrgType(string $type): OrganisationInterface;
}
