<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Organization;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Seo\Api\OrganizationRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organization';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param OrganizationRepositoryInterface $organizationRepository
     */
    public function __construct(
        Context                                          $context,
        private readonly PageFactory                     $resultPageFactory,
        private readonly OrganizationRepositoryInterface $organizationRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Render the Organization settings form.
     *
     * Scope is passed via standard Magento admin query params:
     *   - no param         → global default
     *   - ?website={id}    → website-level override
     *   - ?store={id}      → store-view-level override
     *
     * The entity_id in the URL reflects the actual DB row (0 = unsaved new record).
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        [$scope, $scopeId] = $this->resolveScopeFromRequest();

        $org      = $this->organizationRepository->get($scope, $scopeId);
        $entityId = (int) ($org->getEntityId());

        // Redirect when entity_id is absent or stale so the UI component binds correctly.
        $urlEntityId = $this->getRequest()->getParam('entity_id');
        if ($urlEntityId === null || (int) $urlEntityId !== $entityId) {
            $params = ['entity_id' => $entityId];
            if ($scope === 'websites') {
                $params['website'] = $scopeId;
            } elseif ($scope === 'stores') {
                $params['store'] = $scopeId;
            }
            return $this->resultRedirectFactory->create()->setPath('*/*/edit', $params);
        }

        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Seo::organization');
        $resultPage->getConfig()->getTitle()->prepend((string) __('SEO — Organization Settings'));
        return $resultPage;
    }

    /**
     * Resolve scope and scopeId from the current admin request parameters.
     *
     * @return array{string, int}
     */
    private function resolveScopeFromRequest(): array
    {
        $store = $this->getRequest()->getParam('store');
        if ($store !== null) {
            return ['stores', (int) $store];
        }
        $website = $this->getRequest()->getParam('website');
        if ($website !== null) {
            return ['websites', (int) $website];
        }
        return ['default', 0];
    }
}
