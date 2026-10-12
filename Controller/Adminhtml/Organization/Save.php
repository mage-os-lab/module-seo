<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Organization;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Organization\LogoUrl;
use MageOS\Seo\Model\Organization\UrlValidator;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organization';

    /**
     * @param Context $context
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param UrlValidator $urlValidator
     * @param LogoUrl $logoUrl
     */
    public function __construct(
        Context                                          $context,
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly UrlValidator                    $urlValidator,
        private readonly LogoUrl                         $logoUrl
    ) {
        parent::__construct($context);
    }

    /**
     * Save Organization settings submitted from the UI component form.
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
            $org = $this->organizationRepository->get($scope, $scopeId);

            if (isset($data['name'])) {
                $org->setName((string) $data['name']);
            }
            if (isset($data['url'])) {
                $url = (string) $data['url'];
                // These values are published into JSON-LD and Open Graph tags, so a scheme the
                // browser will execute is refused rather than stored.
                if (!$this->urlValidator->isValid($url)) {
                    $this->messageManager->addErrorMessage(
                        __('The organization URL must be an http:// or https:// address.')
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

            // The logo: the choice is stored, and the URL is worked out per store view when a page
            // is rendered (Model\Organization\LogoUrl). The custom logo is kept while the design
            // logo is used, so switching back finds it.
            if (($data['use_design_logo'] ?? '1') === '1') {
                $org->setLogoSource(OrganizationInterface::LOGO_SOURCE_DESIGN);
            } else {
                // fileUploader submits a list of file objects; an upload's `file` is its media path.
                $uploadData = $data['logo_upload'] ?? [];
                $first      = \is_array($uploadData) && $uploadData !== [] ? (array) reset($uploadData) : [];
                $file       = trim((string) ($first['file'] ?? ''));
                $url        = trim((string) ($first['url'] ?? ''));

                if ($file !== '' && $this->logoUrl->isUpload($file)) {
                    $org->setLogoPath($file);
                } elseif ($file !== '' || ($url !== '' && $url !== $org->getLogoPath())) {
                    // Only an older record's URL comes back without a file, and only unchanged.
                    $this->messageManager->addErrorMessage(__('The logo must be an image uploaded with this form.'));

                    return $resultRedirect->setPath('*/*/edit', $this->redirectParams(0));
                } elseif ($url === '') {
                    // Removed in the form.
                    $org->setLogoPath('');
                }
                $org->setLogoSource(OrganizationInterface::LOGO_SOURCE_CUSTOM);
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

            // Saving purges cached pages automatically: the Organization model's
            // identities (clean_cache_by_tags via AbstractModel) match the tag the
            // JsonLd block puts on every FPC entry — no cache-type invalidation. The feeds
            // that show the Organization queue their own rebuild from the model's save event.
            $this->organizationRepository->save($org);
            $savedEntityId = (int) ($org->getEntityId());
            $this->messageManager->addSuccessMessage(__('Organization settings have been saved.'));
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
}
