<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\DataProvider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\ResourceModel\Organisation\CollectionFactory;

class OrganisationDataProvider extends AbstractDataProvider
{
    /**
     * Config path for the design logo set in Stores > Design > Logo.
     */
    private const DESIGN_LOGO_CONFIG_PATH = 'design/header/logo_src';
    private const SINGLETON_ID = 1;

    /** @var array<int, mixed> */
    private array $loadedData = [];

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param IoFile $ioFile
     * @param mixed[] $meta
     * @param mixed[] $data
     */
    public function __construct(
        string                                           $name,
        string                                           $primaryFieldName,
        string                                           $requestFieldName,
        CollectionFactory                                $collectionFactory,
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly StoreManagerInterface           $storeManager,
        private readonly IoFile                          $ioFile,
        array                                            $meta = [],
        array                                            $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * Return form data hydrated from the Organisation singleton record.
     *
     * Keyed by SINGLETON_ID (1) — the UI component form provider maps this
     * to the form's dataScope="data" automatically via the primaryFieldName
     * entity_id binding. This matches the pattern used by EnrollContent\DataProvider.
     *
     * @return mixed[]
     */
    public function getData(): array
    {
        if (!empty($this->loadedData)) {
            return $this->loadedData;
        }

        $org          = $this->organisationRepository->get();
        $contactPoint = $org->getContactPoint();

        // Convert flat URL array to dynamicRows row objects
        $socialProfileRows = array_map(
            static fn (string $url) => ['url' => $url, 'delete' => ''],
            array_filter($org->getSocialProfiles())
        );

        // Determine logo source toggle value
        $storedLogoPath = $org->getLogoPath();
        $designLogoPath = $this->resolveDesignLogoUrl();
        $useDesignLogo  = ($storedLogoPath === '' || $storedLogoPath === $designLogoPath) ? '1' : '0';

        // Populate the uploader field when a custom logo is stored
        $logoUpload = [];
        if ($useDesignLogo === '0' && $storedLogoPath !== '') {
            $pathInfo = $this->ioFile->getPathInfo($storedLogoPath);
            $logoUpload = [
                [
                    'url'         => $storedLogoPath,
                    'name'        => $pathInfo['basename'] ?? '',
                    'type'        => 'image/' . strtolower($pathInfo['extension'] ?? ''),
                    'size'        => 0,
                    'previewType' => 'image',
                ],
            ];
        }

        $this->loadedData[self::SINGLETON_ID] = [
            'entity_id'                => self::SINGLETON_ID,
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
        ];

        return $this->loadedData;
    }

    /**
     * Resolve the absolute URL of the current design logo from store config.
     *
     * @return string
     */
    private function resolveDesignLogoUrl(): string
    {
        $logoFile = (string) $this->scopeConfig->getValue(
            self::DESIGN_LOGO_CONFIG_PATH,
            ScopeInterface::SCOPE_STORE
        );

        if ($logoFile === '') {
            return '';
        }

        try {
            $mediaUrl = (string) $this->storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            );
            return rtrim($mediaUrl, '/') . '/logo/' . ltrim($logoFile, '/');
        } catch (\Exception) {
            return '';
        }
    }
}
