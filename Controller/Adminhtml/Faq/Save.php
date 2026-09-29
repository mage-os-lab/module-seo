<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Adminhtml\Faq;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Model\FaqFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Faq::faq';

    /**
     * @param Context $context
     * @param FaqRepositoryInterface $faqRepository
     * @param FaqFactory $faqFactory
     * @param DataPersistorInterface $dataPersistor
     */
    public function __construct(
        Context                                 $context,
        private readonly FaqRepositoryInterface $faqRepository,
        private readonly FaqFactory             $faqFactory,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    /**
     * Persist a FAQ entry submitted from the form.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        /** @var \Magento\Framework\App\Request\Http $request */
        $request        = $this->getRequest();
        $data           = $request->getPostValue();

        if (empty($data)) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);

        try {
            $faq = $entityId !== 0 ? $this->faqRepository->getById($entityId) : $this->faqFactory->create();
            $this->populate($faq, $data);
            // Cached pages purge via the model's identities; the feeds that show FAQs queue their
            // own rebuild from the model's save event.
            $this->faqRepository->save($faq);

            $this->messageManager->addSuccessMessage((string) __('The FAQ entry has been saved.'));
            $this->dataPersistor->clear('mageos_faq');

            if ($this->getRequest()->getParam('back') !== null) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $faq->getEntityId()]);
            }
            return $resultRedirect->setPath('*/*/');
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('This FAQ no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set('mageos_faq', $data);
            $back = $entityId !== 0 ? ['entity_id' => $entityId] : [];
            return $resultRedirect->setPath('*/*/edit', $back);
        }
    }

    /**
     * Apply submitted form values to the FAQ model.
     *
     * @param FaqInterface $faq
     * @param mixed[] $data
     * @throws LocalizedException When a required field is empty
     * @return void
     */
    private function populate(FaqInterface $faq, array $data): void
    {
        $identifier = trim((string) ($data['identifier'] ?? ''));
        $question   = trim((string) ($data['question'] ?? ''));
        $answer     = trim((string) ($data['answer'] ?? ''));

        if ($identifier === '' || $question === '' || $answer === '') {
            throw new LocalizedException(__('Identifier, question and answer are required.'));
        }

        $faq->setIdentifier($identifier);
        $faq->setStoreId(max(0, (int) ($data['store_id'] ?? 0)));
        $faq->setQuestion($question);
        $faq->setAnswer($answer);
        $faq->setSortOrder((int) ($data['sort_order'] ?? 0));
        $faq->setIsActive((bool) ($data['is_active'] ?? false));
    }
}
