<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Organisation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Organisation\UrlValidator;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organisation';

    private const DESIGN_LOGO_CONFIG_PATH = 'design/header/logo_src';

    /**
     * @param Context $context
     * @param OrganisationRepositoryInterface $organisationRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param FeedInvalidator $feedInvalidator
     * @param UrlValidator $urlValidator
     */
    public function __construct(
        Context                                          $context,
        private readonly OrganisationRepositoryInterface $organisationRepository,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly StoreManagerInterface           $storeManager,
        private readonly FeedInvalidator                 $feedInvalidator,
        private readonly UrlValidator                    $urlValidator
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
                $url = (string) $data['url'];
                // These values are published into JSON-LD and Open Graph tags, so a scheme the
                // browser will execute is refused rather than stored.
                if (!$this->urlValidator->isValid($url)) {
                    $this->messageManager->addErrorMessage(
                        __('The organisation URL must be an http:// or https:// address.')
                    );

                    return $resultRedirect->setPath('*/*/edit', $this->redirectParams(0));
                }
                $org->setUrl($url);
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
                    $url   = (string) ($first['url'] ?? '');
                    if ($url !== '' && !$this->urlValidator->isValid($url)) {
                        $this->messageManager->addErrorMessage(
                            __('The logo URL must be an http:// or https:// address or a media path.')
                        );

                        return $resultRedirect->setPath('*/*/edit', $this->redirectParams(0));
                    }
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

                // A profile that cannot be published is dropped rather than failing the save:
                // the rest of the record is valid, and the message names what was left out.
                $safeProfiles = $this->urlValidator->filter($profiles);
                if (\count($safeProfiles) !== \count($profiles)) {
                    $this->messageManager->addErrorMessage(
                        __(
                            'These social profiles were not saved — a profile must be an http:// or'
                            . ' https:// address: %1',
                            implode(', ', array_diff($profiles, $safeProfiles))
                        )
                    );
                }
                $org->setSocialProfiles($safeProfiles);
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

            // LocalBusiness presence fields
            $localKeys = [
                'street_address', 'address_locality', 'address_region', 'postal_code',
                'address_country', 'telephone', 'email', 'latitude', 'longitude', 'price_range',
            ];
            $localData = [];
            foreach ($localKeys as $key) {
                if (isset($data[$key])) {
                    $localData[$key] = (string) $data[$key];
                }
            }
            if ($localData !== []) {
                $org->setLocalPresence($localData);
            }

            // Saving purges cached pages automatically: the Organisation model's
            // identities (clean_cache_by_tags via AbstractModel) match the tag the
            // JsonLd block puts on every FPC entry — no cache-type invalidation.
            $this->organisationRepository->save($org);
            $savedEntityId = (int) ($org->getEntityId());
            // llms.txt / llms-full.txt embed organisation data; regenerate their files.
            $this->feedInvalidator->invalidateLlms();
            $this->messageManager->addSuccessMessage(__('Organisation settings have been saved.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not save: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/edit', $this->redirectParams($savedEntityId));
    }

    /**
     * Parameters that send the admin back to the form in the scope they were editing.
     *
     * @param int $savedEntityId
     * @return array<string, int>
     */
    private function redirectParams(int $savedEntityId): array
    {
        [$scope, $scopeId] = $this->resolveScopeFromRequest();
        $params = ['entity_id' => $savedEntityId];
        if ($scope === 'websites') {
            $params['website'] = $scopeId;
        } elseif ($scope === 'stores') {
            $params['store'] = $scopeId;
        }

        return $params;
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
