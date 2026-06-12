<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Organisation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organisation';

    private const DESIGN_LOGO_CONFIG_PATH = 'design/header/logo_src';

    /**
     * @param Context $context
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param TypeListInterface $cacheTypeList
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context                                          $context,
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly TypeListInterface               $cacheTypeList,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly StoreManagerInterface           $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * Save Organisation settings submitted from the UI component form.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        /** @var \Magento\Framework\App\Request\Http $httpRequest */
        $httpRequest = $this->getRequest();
        $data = $httpRequest->getPostValue();

        if (empty($data)) {
            $this->messageManager->addErrorMessage(__('No data received.'));
            return $resultRedirect->setPath('*/*/edit');
        }

        $savedEntityId = 0;

        try {
            [$scope, $scopeId] = $this->resolveScopeFromRequest();
            $org = $this->organisationRepository->get($scope, $scopeId);

            if (isset($data['name'])) {
                $org->setName((string) $data['name']);
            }
            if (isset($data['url'])) {
                $org->setUrl((string) $data['url']);
            }
            if (isset($data['org_type'])) {
                $org->setOrgType((string) $data['org_type']);
            }
            if (isset($data['description'])) {
                $org->setDescription((string) $data['description']);
            }
            if (isset($data['logo_width'])) {
                $org->setLogoWidth((int) $data['logo_width']);
            }
            if (isset($data['logo_height'])) {
                $org->setLogoHeight((int) $data['logo_height']);
            }

            // Logo path — resolve from toggle selection
            $useDesignLogo = ($data['use_design_logo'] ?? '1') === '1';
            if ($useDesignLogo) {
                $org->setLogoPath($this->resolveDesignLogoUrl());
            } else {
                // Custom upload — fileUploader submits an array of file objects
                $uploadData = $data['logo_upload'] ?? [];
                if (!empty($uploadData) && \is_array($uploadData)) {
                    $first = reset($uploadData);
                    $url   = $first['url'] ?? '';
                    if ($url !== '') {
                        $org->setLogoPath($url);
                    }
                }
            }

            // Social profiles — dynamicRows submits [['url' => '...', 'delete' => ''], ...]
            // Filter out deleted rows and rows without a URL, then flatten to a plain URL array
            $profileRows = $data['social_profiles'] ?? [];
            if (\is_array($profileRows)) {
                $profiles = [];
                foreach ($profileRows as $row) {
                    if (!empty($row['delete'])) {
                        continue; // deleted row
                    }
                    $url = trim((string) ($row['url'] ?? ''));
                    if ($url !== '') {
                        $profiles[] = $url;
                    }
                }
                $org->setSocialProfiles($profiles);
            }

            // Contact point
            $contact = [];
            foreach (['contactType', 'email', 'availableLanguage'] as $key) {
                $formKey = 'contact_' . $key;
                if (!empty($data[$formKey])) {
                    $contact[$key] = (string) $data[$formKey];
                }
            }
            $org->setContactPoint($contact);

            $this->organisationRepository->save($org);
            $savedEntityId = (int) ($org->getEntityId());
            $this->cacheTypeList->invalidate(['full_page', 'config']);
            $this->messageManager->addSuccessMessage(__('Organisation settings have been saved.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not save: %1', $e->getMessage()));
        }

        [$scope, $scopeId] = $this->resolveScopeFromRequest();
        $params = ['entity_id' => $savedEntityId];
        if ($scope === 'websites') {
            $params['website'] = $scopeId;
        } elseif ($scope === 'stores') {
            $params['store'] = $scopeId;
        }

        return $resultRedirect->setPath('*/*/edit', $params);
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
        $storeParam = $this->getRequest()->getParam('store');
        if ($storeParam !== null) {
            return ['stores', (int) $storeParam];
        }

        $websiteParam = $this->getRequest()->getParam('website');
        if ($websiteParam !== null) {
            return ['websites', (int) $websiteParam];
        }

        return ['default', 0];
    }

    /**
     * Resolve the absolute URL of the design logo from store config.
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
