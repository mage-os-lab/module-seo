<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Model\ResourceModel\Organization as OrganizationResource;

class Organization extends AbstractModel implements OrganizationInterface, IdentityInterface
{
    /**
     * Cache tag carried by every FPC page (Organization data renders in the
     * Organization/WebSite schema and OG tags on all pages), so saving a row
     * purges the affected pages automatically via AbstractModel's
     * clean_cache_by_tags dispatch — no manual cache-type invalidation needed.
     */
    public const CACHE_TAG = 'mageos_seo_organization';

    /**
     * Saves and deletes dispatch `mageos_seo_organization_save_after` / `_delete_after`, so whatever
     * shows the Organization elsewhere (the llms documents, for one) can react to any save.
     *
     * @var string
     */
    protected $_eventPrefix = 'mageos_seo_organization';

    /**
     * @var string
     */
    protected $_eventObject = 'organization';

    /**
     * Initialize resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(OrganizationResource::class);
    }

    /**
     * @inheritdoc
     */
    public function getIdentities(): array
    {
        return [self::CACHE_TAG];
    }

    /**
     * @inheritdoc
     */
    public function getEntityId(): int
    {
        return (int) $this->getData(self::ENTITY_ID);
    }

    /**
     * @inheritdoc
     */
    public function getScope(): string
    {
        return (string) ($this->getData(self::SCOPE) ?: 'default');
    }

    /**
     * @inheritdoc
     */
    public function setScope(string $scope): OrganizationInterface
    {
        return $this->setData(self::SCOPE, $scope);
    }

    /**
     * @inheritdoc
     */
    public function getScopeId(): int
    {
        return (int) $this->getData(self::SCOPE_ID);
    }

    /**
     * @inheritdoc
     */
    public function setScopeId(int $scopeId): OrganizationInterface
    {
        return $this->setData(self::SCOPE_ID, $scopeId);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    /**
     * @inheritdoc
     */
    public function setName(string $name): OrganizationInterface
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * @inheritdoc
     */
    public function getUrl(): string
    {
        return (string) $this->getData(self::URL);
    }

    /**
     * @inheritdoc
     */
    public function setUrl(string $url): OrganizationInterface
    {
        return $this->setData(self::URL, $url);
    }

    /**
     * @inheritdoc
     */
    public function getLogoPath(): string
    {
        return (string) $this->getData(self::LOGO_PATH);
    }

    /**
     * @inheritdoc
     */
    public function setLogoPath(string $logoPath): OrganizationInterface
    {
        return $this->setData(self::LOGO_PATH, $logoPath);
    }

    /**
     * @inheritdoc
     */
    public function getLogoWidth(): int
    {
        return (int) $this->getData(self::LOGO_WIDTH);
    }

    /**
     * @inheritdoc
     */
    public function setLogoWidth(int $width): OrganizationInterface
    {
        return $this->setData(self::LOGO_WIDTH, $width);
    }

    /**
     * @inheritdoc
     */
    public function getLogoHeight(): int
    {
        return (int) $this->getData(self::LOGO_HEIGHT);
    }

    /**
     * @inheritdoc
     */
    public function setLogoHeight(int $height): OrganizationInterface
    {
        return $this->setData(self::LOGO_HEIGHT, $height);
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return (string) $this->getData(self::DESCRIPTION);
    }

    /**
     * @inheritdoc
     */
    public function setDescription(string $description): OrganizationInterface
    {
        return $this->setData(self::DESCRIPTION, $description);
    }

    /**
     * @inheritdoc
     */
    public function getSocialProfiles(): array
    {
        $raw = $this->getData(self::SOCIAL_PROFILES);
        if (empty($raw)) {
            return [];
        }
        return \is_array($raw) ? $raw : (json_decode($raw, true) ?? []);
    }

    /**
     * @inheritdoc
     */
    public function setSocialProfiles(array $profiles): OrganizationInterface
    {
        return $this->setData(self::SOCIAL_PROFILES, json_encode($profiles));
    }

    /**
     * @inheritdoc
     */
    public function getContactPoint(): array
    {
        $raw = $this->getData(self::CONTACT_POINT);
        if (empty($raw)) {
            return [];
        }
        return \is_array($raw) ? $raw : (json_decode($raw, true) ?? []);
    }

    /**
     * @inheritdoc
     */
    public function setContactPoint(array $contactPoint): OrganizationInterface
    {
        return $this->setData(self::CONTACT_POINT, json_encode($contactPoint));
    }

    /**
     * @inheritdoc
     */
    public function getOrgType(): string
    {
        return (string) ($this->getData(self::ORG_TYPE) ?: 'Organization');
    }

    /**
     * @inheritdoc
     */
    public function setOrgType(string $type): OrganizationInterface
    {
        return $this->setData(self::ORG_TYPE, $type);
    }

    /**
     * @inheritdoc
     */
    public function getAddress(): array
    {
        return [
            'street_address'   => (string) $this->getData(self::STREET_ADDRESS),
            'address_locality' => (string) $this->getData(self::ADDRESS_LOCALITY),
            'address_region'   => (string) $this->getData(self::ADDRESS_REGION),
            'postal_code'      => (string) $this->getData(self::POSTAL_CODE),
            'address_country'  => (string) $this->getData(self::ADDRESS_COUNTRY),
        ];
    }

    /**
     * @inheritdoc
     */
    public function setLocalPresence(array $data): OrganizationInterface
    {
        $fields = [
            self::STREET_ADDRESS,
            self::ADDRESS_LOCALITY,
            self::ADDRESS_REGION,
            self::POSTAL_CODE,
            self::ADDRESS_COUNTRY,
            self::TELEPHONE,
            self::EMAIL,
            self::LATITUDE,
            self::LONGITUDE,
            self::PRICE_RANGE,
        ];
        foreach ($fields as $field) {
            if (\array_key_exists($field, $data)) {
                $this->setData($field, (string) $data[$field]);
            }
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getLatitude(): string
    {
        return (string) $this->getData(self::LATITUDE);
    }

    /**
     * @inheritdoc
     */
    public function getLongitude(): string
    {
        return (string) $this->getData(self::LONGITUDE);
    }

    /**
     * @inheritdoc
     */
    public function getTelephone(): string
    {
        return (string) $this->getData(self::TELEPHONE);
    }

    /**
     * @inheritdoc
     */
    public function getEmail(): string
    {
        return (string) $this->getData(self::EMAIL);
    }

    /**
     * @inheritdoc
     */
    public function getPriceRange(): string
    {
        return (string) $this->getData(self::PRICE_RANGE);
    }
}
