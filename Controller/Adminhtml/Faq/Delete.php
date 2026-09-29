<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Faq;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Seo\Api\FaqRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Faq::faq';

    /**
     * @param Context $context
     * @param FaqRepositoryInterface $faqRepository
     */
    public function __construct(
        Context                                 $context,
        private readonly FaqRepositoryInterface $faqRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Delete a FAQ entry.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId       = (int) $this->getRequest()->getParam('entity_id');

        if ($entityId === 0) {
            $this->messageManager->addErrorMessage((string) __('No FAQ specified to delete.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            // Cached pages purge via the model's identities; the feeds that show FAQs queue their
            // own rebuild from the model's delete event.
            $this->faqRepository->deleteById($entityId);
            $this->messageManager->addSuccessMessage((string) __('The FAQ entry has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('This FAQ no longer exists.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }
}
