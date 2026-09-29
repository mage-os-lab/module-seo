<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Faq;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Seo\Api\FaqRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Faq::faq';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param FaqRepositoryInterface $faqRepository
     */
    public function __construct(
        Context                                 $context,
        private readonly PageFactory            $resultPageFactory,
        private readonly FaqRepositoryInterface $faqRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Render the FAQ edit/new form.
     *
     * @return Page|Redirect
     */
    public function execute(): Page|Redirect
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if ($entityId !== 0) {
            try {
                $this->faqRepository->getById($entityId);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage((string) __('This FAQ no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Faq::faq');
        $resultPage->getConfig()->getTitle()->prepend(
            $entityId !== 0 ? (string) __('Edit FAQ') : (string) __('New FAQ')
        );
        return $resultPage;
    }
}
