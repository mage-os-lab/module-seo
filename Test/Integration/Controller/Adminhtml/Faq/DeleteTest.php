<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Faq;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Model\Faq;

/**
 * Deleting an entry from the grid removes it.
 *
 * Core's testAclHasAccess / testAclNoAccess run too, because $uri, $resource and $httpMethod are
 * set.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class DeleteTest extends AbstractBackendController
{
    /**
     * @var string|null
     */
    protected $resource = 'MageOS_Faq::faq';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_faq/faq/delete';

    /**
     * @var string|null
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * @return void
     */
    public function testTheEntryIsDeleted(): void
    {
        /** @var Faq $faq */
        $faq = $this->_objectManager->create(Faq::class);
        $faq->setIdentifier('mageos-faq-admin-' . uniqid());
        $faq->setStoreId(0);
        $faq->setQuestion('Q');
        $faq->setAnswer('A');
        $faq->setIsActive(true);
        $repository = $this->_objectManager->get(FaqRepositoryInterface::class);
        $entityId   = (int) $repository->save($faq)->getEntityId();

        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue(['entity_id' => (string) $entityId]);
        $this->dispatch($this->uri);

        $this->assertSessionMessages(
            $this->containsEqual('The FAQ entry has been deleted.'),
            MessageInterface::TYPE_SUCCESS
        );
        $this->expectException(NoSuchEntityException::class);
        $this->_objectManager->create(FaqRepositoryInterface::class)->getById($entityId);
    }
}
