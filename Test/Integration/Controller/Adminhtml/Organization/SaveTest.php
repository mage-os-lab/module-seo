<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Organization;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Api\OrganizationRepositoryInterface;

/**
 * Saving the Organization settings form stores the record for the scope and returns to the form.
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
    protected $resource = 'MageOS_Seo::organization';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_seo/organization/save';

    /**
     * @var string|null
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * The default scope's record holds what the form posted, and the admin is sent back to it.
     *
     * @return void
     */
    public function testTheFormIsSavedForTheDefaultScope(): void
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue([
            'name'            => 'Acme Test Organization',
            'url'             => 'https://acme.example',
            'use_design_logo' => '1',
        ]);
        $this->dispatch($this->uri);

        $this->assertSessionMessages(
            $this->containsEqual('Organization settings have been saved.'),
            MessageInterface::TYPE_SUCCESS
        );
        $this->assertRedirect($this->stringContains('mageos_seo/organization/edit/entity_id/'));

        // A new repository: the shared one may hold the record as it was before the save.
        $organization = $this->_objectManager->create(OrganizationRepositoryInterface::class)->get('default', 0);
        $this->assertSame('Acme Test Organization', $organization->getName());
        $this->assertSame('https://acme.example', $organization->getUrl());
    }
}
