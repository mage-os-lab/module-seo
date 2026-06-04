<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Organisation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'MageOS_Seo::organisation';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context                          $context,
        private readonly PageFactory     $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Redirect to ensure entity_id=1 is always in the URL.
     * The DataProvider needs this as the record context even
     * though it always loads the singleton row directly.
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        if (!$this->getRequest()->getParam('entity_id')) {
            return $this->resultRedirectFactory->create()
                ->setPath('*/*/edit', ['entity_id' => 1]);
        }

        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Seo::organisation');
        $resultPage->getConfig()->getTitle()->prepend((string) __('SEO — Organisation Settings'));
        return $resultPage;
    }
}
