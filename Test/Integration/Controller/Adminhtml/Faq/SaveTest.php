<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Faq;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Model\Faq\GroupReader;

/**
 * Saving the FAQ form stores the entry and returns to the grid.
 *
 * Core's testAclHasAccess / testAclNoAccess run too, because $uri, $resource and $httpMethod are
 * set.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class SaveTest extends AbstractBackendController
{
    /**
     * @var string|null
     */
    protected $resource = 'MageOS_Faq::faq';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_faq/faq/save';

    /**
     * @var string|null
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * @return void
     */
    public function testANewEntryIsSavedAndTheAdminReturnsToTheGrid(): void
    {
        $identifier = 'mageos-faq-admin-' . uniqid();

        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue([
            'identifier' => $identifier,
            'store_id'   => '0',
            'question'   => 'Where is my order?',
            'answer'     => 'On its way.',
            'is_active'  => '1',
        ]);
        $this->dispatch($this->uri);

        $this->assertSessionMessages(
            $this->containsEqual('The FAQ entry has been saved.'),
            MessageInterface::TYPE_SUCCESS
        );
        $this->assertRedirect($this->stringContains('mageos_faq/faq/'));

        $saved = $this->_objectManager->create(GroupReader::class)->getByIdentifier($identifier, 0);
        $this->assertSame(['Where is my order?'], array_column($saved, 'question'));
        $this->assertSame(['On its way.'], array_column($saved, 'answer'));
    }
}
