<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\DataProvider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Ui\DataProvider\AbstractDataProvider;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Organization\LogoUrl;
use MageOS\Seo\Model\ResourceModel\Organization\CollectionFactory;

class OrganizationDataProvider extends AbstractDataProvider
{
    /** @var array<int, mixed> */
    private array $loadedData = [];

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param LogoUrl $logoUrl
     * @param IoFile $ioFile
     * @param RequestInterface $request
     * @param UrlInterface $urlBuilder
     * @param mixed[] $meta
     * @param mixed[] $data
     */
    public function __construct(
        string                                           $name,
        string                                           $primaryFieldName,
        string                                           $requestFieldName,
        CollectionFactory                                $collectionFactory,
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly LogoUrl                         $logoUrl,
        private readonly IoFile                          $ioFile,
        private readonly RequestInterface                $request,
        private readonly UrlInterface                    $urlBuilder,
        array                                            $meta = [],
        array                                            $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * Return form data hydrated from the Organization record for the current scope.
     *
     * Keyed by entity_id — the UI component form provider maps this to the form's
     * dataScope="data" automatically via the primaryFieldName entity_id binding.
     *
     * @return mixed[]
     */
    public function getData(): array
    {
        if (!empty($this->loadedData)) {
            return $this->loadedData;
        }

        [$scope, $scopeId] = $this->resolveScopeFromRequest();
        $org               = $this->organizationRepository->get($scope, $scopeId);
        $contactPoint      = $org->getContactPoint();

        // Convert flat URL array to dynamicRows row objects
        $socialProfileRows = array_map(
            static fn (string $url) => ['url' => $url, 'delete' => ''],
            array_filter($org->getSocialProfiles())
        );

        // The stored choice, never a guess from today's design logo (issue #37).
        $useDesignLogo  = $org->getLogoSource() === OrganizationInterface::LOGO_SOURCE_DESIGN ? '1' : '0';
        $storedLogoPath = $org->getLogoPath();

        // The custom logo, kept while the design logo is used so switching back finds it. An upload
        // carries its media path as `file`, which the form posts back; an older record's absolute
        // URL has none. The URL is the admin's preview only.
        $logoUpload = [];
        if ($storedLogoPath !== '') {
            $pathInfo = $this->ioFile->getPathInfo($storedLogoPath);
            $item     = [
                'url'         => (string) $this->logoUrl->customLogo($storedLogoPath, Store::DEFAULT_STORE_ID),
                'name'        => $pathInfo['basename'] ?? '',
                'type'        => 'image/' . strtolower($pathInfo['extension'] ?? ''),
                'size'        => 0,
                'previewType' => 'image',
            ];
            if ($this->logoUrl->isUpload($storedLogoPath)) {
                $item['file'] = $storedLogoPath;
            }
            $logoUpload = [$item];
        }

        $entityId = (int) ($org->getEntityId());

        $this->loadedData[$entityId] = [
            'entity_id'                => $entityId,
            'name'                     => $org->getName(),
            'url'                      => $org->getUrl(),
            'org_type'                 => $org->getOrgType(),
            'description'              => $org->getDescription(),
            'use_design_logo'          => $useDesignLogo,
            'logo_upload'              => $logoUpload,
            'logo_width'               => $org->getLogoWidth() ?: '',
            'logo_height'              => $org->getLogoHeight() ?: '',
            'social_profiles'          => array_values($socialProfileRows),
            'contact_contactType'      => $contactPoint['contactType'] ?? '',
            'contact_email'            => $contactPoint['email'] ?? '',
            'contact_availableLanguage' => $contactPoint['availableLanguage'] ?? '',
            'street_address'           => $org->getAddress()['street_address'],
            'address_locality'         => $org->getAddress()['address_locality'],
            'address_region'           => $org->getAddress()['address_region'],
            'postal_code'              => $org->getAddress()['postal_code'],
            'address_country'          => $org->getAddress()['address_country'],
            'telephone'                => $org->getTelephone(),
            'email'                    => $org->getEmail(),
            'latitude'                 => $org->getLatitude(),
            'longitude'                => $org->getLongitude(),
            'price_range'              => $org->getPriceRange(),
        ];

        return $this->loadedData;
    }

    /**
     * Add to the Logo Source field what the scope being edited publishes with "Use Design Logo".
     *
     * A store publishes its own design logo; a website or the default serves several stores, each
     * of which publishes its own (decision 3 (a) of issue #37).
     *
     * @return mixed[]
     */
    public function getMeta(): array
    {
        $meta = parent::getMeta();

        [$scope, $scopeId] = $this->resolveScopeFromRequest();
        if ($scope === 'stores') {
            $designLogo = $this->logoUrl->designLogo($scopeId);
            $scopeLine  = $designLogo === null
                ? __(
                    'With Use Design Logo, this store publishes no logo: it has none under'
                    . ' Content → Design → Configuration.'
                )
                : __('With Use Design Logo, this store publishes %1.', $designLogo);
        } else {
            $scopeLine = __(
                'With Use Design Logo, each store publishes its own, from Content → Design → Configuration.'
            );
        }

        $meta['organization_logo']['children']['use_design_logo']['arguments']['data']['config']['notice']
            = __('Use your existing Design logo, or upload a dedicated SEO logo (recommended: min 112x112px).')
            . ' ' . $scopeLine;

        return $meta;
    }

    /**
     * Return data source config with a scope-aware submit URL.
     *
     * @return mixed[]
     */
    public function getConfigData(): array
    {
        [$scope, $scopeId] = $this->resolveScopeFromRequest();
        $params = match ($scope) {
            'stores'   => ['store'   => $scopeId],
            'websites' => ['website' => $scopeId],
            default    => [],
        };

        return array_merge(
            parent::getConfigData(),
            ['submit_url' => $this->urlBuilder->getUrl('mageos_seo/organization/save', $params)]
        );
    }

    /**
     * Resolve scope + scopeId from the current request.
     *
     * Priority: store param → website param → global default.
     *
     * @return array{string, int}
     */
    private function resolveScopeFromRequest(): array
    {
        $store = $this->request->getParam('store');
        if ($store !== null) {
            return ['stores', (int) $store];
        }
        $website = $this->request->getParam('website');
        if ($website !== null) {
            return ['websites', (int) $website];
        }
        return ['default', 0];
    }
}
