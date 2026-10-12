<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml\Organization;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Api\Data\OrganizationInterface;
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

    /**
     * Issue #37: "Use Design Logo" is stored as the choice, not as today's design logo URL. The last
     * custom upload is kept, so switching back finds it.
     *
     * @return void
     */
    public function testUseDesignLogoIsStoredAsTheChoice(): void
    {
        $this->storedOrganization(OrganizationInterface::LOGO_SOURCE_CUSTOM, 'mage-os/seo/logo/square.png');

        $this->post(['use_design_logo' => '1']);

        $organization = $this->savedOrganization();
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_DESIGN, $organization->getLogoSource());
        $this->assertSame('mage-os/seo/logo/square.png', $organization->getLogoPath());
    }

    /**
     * Decision 1 (a): an upload is stored media-relative, to be published under each store view's
     * media URL rather than the admin's.
     *
     * @return void
     */
    public function testACustomUploadIsStoredMediaRelative(): void
    {
        $this->post([
            'use_design_logo' => '0',
            'logo_upload'     => [[
                'name' => 'square.png',
                'file' => 'mage-os/seo/logo/square.png',
                'url'  => 'http://admin.example/media/mage-os/seo/logo/square.png',
            ]],
        ]);

        $organization = $this->savedOrganization();
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_CUSTOM, $organization->getLogoSource());
        $this->assertSame('mage-os/seo/logo/square.png', $organization->getLogoPath());
    }

    /**
     * Only a file uploaded with the form is a custom logo.
     *
     * @return void
     */
    public function testAPathOutsideTheLogoFolderIsRefused(): void
    {
        $this->post([
            'name'            => 'Not saved',
            'use_design_logo' => '0',
            'logo_upload'     => [['name' => 'env.php', 'file' => 'mage-os/seo/logo/../../../app/etc/env.php']],
        ]);

        $this->assertSessionMessages(
            $this->containsEqual('The logo must be an image uploaded with this form.'),
            MessageInterface::TYPE_ERROR
        );
        $this->assertNotSame('Not saved', $this->savedOrganization()->getName());
    }

    /**
     * An older record's absolute logo URL, posted back unchanged, is kept.
     *
     * @return void
     */
    public function testAnOlderRecordsLogoUrlIsKeptWhileItIsNotReplaced(): void
    {
        $this->storedOrganization(null, 'https://cdn.example/brand.png');

        $this->post([
            'use_design_logo' => '0',
            'logo_upload'     => [['name' => 'brand.png', 'url' => 'https://cdn.example/brand.png']],
        ]);

        $organization = $this->savedOrganization();
        $this->assertSame('https://cdn.example/brand.png', $organization->getLogoPath());
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_CUSTOM, $organization->getLogoSource());
    }

    /**
     * A custom logo removed from the form is removed from the record: none is published.
     *
     * @return void
     */
    public function testACustomLogoRemovedInTheFormIsRemoved(): void
    {
        $this->storedOrganization(OrganizationInterface::LOGO_SOURCE_CUSTOM, 'mage-os/seo/logo/square.png');

        $this->post(['use_design_logo' => '0']);

        $this->assertSame('', $this->savedOrganization()->getLogoPath());
    }

    /**
     * Post the form for the default scope, with a name and URL unless given.
     *
     * @param array<string, mixed> $values
     * @return void
     */
    private function post(array $values): void
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue(array_merge(
            ['name' => 'Acme', 'url' => 'https://acme.example'],
            $values
        ));
        $this->dispatch($this->uri);
    }

    /**
     * Store the default scope's record before the form is posted.
     *
     * @param string|null $source Null for a record saved before the source was stored
     * @param string $logoPath
     * @return void
     */
    private function storedOrganization(?string $source, string $logoPath): void
    {
        $repository   = $this->_objectManager->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get('default', 0);
        $organization->setName('Acme');
        $organization->setLogoPath($logoPath);
        if ($source !== null) {
            $organization->setLogoSource($source);
        }
        $repository->save($organization);
    }

    /**
     * The default scope's record as stored, through a new repository.
     *
     * @return OrganizationInterface
     */
    private function savedOrganization(): OrganizationInterface
    {
        return $this->_objectManager->create(OrganizationRepositoryInterface::class)->get('default', 0);
    }
}
