<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Faq;

use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The FAQ Manager grid, as the admin menu opens it.
 *
 * Covers the route, the ACL resource (core's testAclHasAccess / testAclNoAccess, which run because
 * $uri and $resource are set), the layout handle and the listing UI component together.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class IndexTest extends AbstractBackendController
{
    /**
     * @var string|null
     */
    protected $resource = 'MageOS_Faq::faq';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_faq/faq/index';

    /**
     * @return void
     */
    public function testTheGridRendersUnderItsTitle(): void
    {
        $this->dispatch($this->uri);

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $body = (string) $this->getResponse()->getBody();
        $this->assertStringContainsString('mageos_faq_listing', $body);
        $this->assertStringContainsString('FAQ Manager', $body);
    }
}
