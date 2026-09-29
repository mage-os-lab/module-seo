<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Faq;

use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The FAQ form. Without an entity_id it is the form for a new entry.
 *
 * Core's testAclHasAccess / testAclNoAccess run too, because $uri and $resource are set.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class EditTest extends AbstractBackendController
{
    /**
     * @var string|null
     */
    protected $resource = 'MageOS_Faq::faq';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_faq/faq/edit';

    /**
     * @return void
     */
    public function testTheNewEntryFormRenders(): void
    {
        $this->dispatch($this->uri);

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $body = (string) $this->getResponse()->getBody();
        $this->assertStringContainsString('mageos_faq_form', $body);
        $this->assertStringContainsString('New FAQ', $body);
    }
}
