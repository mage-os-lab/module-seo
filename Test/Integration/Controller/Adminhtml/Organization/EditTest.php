<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Organization;

use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Api\OrganizationRepositoryInterface;

/**
 * The Organization settings page, as the admin menu opens it.
 *
 * Covers the route, the ACL resource (core's testAclHasAccess / testAclNoAccess, which run because
 * $uri and $resource are set), the layout handle and the UI component together: a mismatch in any
 * of them leaves the menu item pointing at a 404, a 403 or an empty page.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class EditTest extends AbstractBackendController
{
    /**
     * @var string|null
     */
    protected $resource = 'MageOS_Seo::organization';

    /**
     * @var string|null
     */
    protected $uri = 'backend/mageos_seo/organization/edit';

    /**
     * The menu's URL carries no entity_id, so the controller sends the admin to the scope's record.
     *
     * @return void
     */
    public function testTheMenuUrlRedirectsToTheScopesRecord(): void
    {
        $this->dispatch($this->uri);

        $this->assertRedirect(
            $this->stringContains('mageos_seo/organization/edit/entity_id/' . $this->defaultScopeEntityId() . '/')
        );
    }

    /**
     * The record's URL renders the Organization form under its title.
     *
     * One dispatch per test: resetRequest() leaves the shared response in place, so a page
     * rendered after a redirect would still report the redirect's 302.
     *
     * @return void
     */
    public function testTheRecordsUrlRendersTheOrganizationForm(): void
    {
        $this->dispatch($this->uri . '/entity_id/' . $this->defaultScopeEntityId());

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $body = (string) $this->getResponse()->getBody();
        $this->assertStringContainsString('mageos_seo_organization_form', $body);
        $this->assertStringContainsString('Organization Settings', $body);
    }

    /**
     * The default scope's record ID, 0 while none is saved.
     *
     * @return int
     */
    private function defaultScopeEntityId(): int
    {
        return $this->_objectManager->create(OrganizationRepositoryInterface::class)->get('default', 0)->getEntityId();
    }
}
