<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Ucp;

use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\OrganisationRepository;
use MageOS\Seo\Model\Ucp\AiPluginBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The contact /.well-known/ai-plugin.json publishes, against a real install.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class AiPluginBuilderTest extends TestCase
{
    private const SUPPORT_EMAIL = 'trans_email/ident_support/email';

    /**
     * Drop the Organisations the rolled-back saves left memoised.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(OrganisationRepository::class)->_resetState();
    }

    /**
     * The Organisation's contact email is the manifest's contact, ahead of the store's support email.
     *
     * @return void
     */
    #[Config(self::SUPPORT_EMAIL, 'help@shop.test', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheContactEmailIsTheOrganisationContactEmail(): void
    {
        $repository   = Bootstrap::getObjectManager()->get(OrganisationRepositoryInterface::class);
        $organisation = $repository->get();
        $organisation->setContactPoint(['email' => 'ai@shop.test']);
        $repository->save($organisation);

        $this->assertSame('ai@shop.test', $this->contactEmail());
    }

    /**
     * Without an Organisation contact, the store's configured support email is used.
     *
     * @return void
     */
    #[Config(self::SUPPORT_EMAIL, 'help@shop.test', ScopeInterface::SCOPE_STORE, 'default')]
    public function testWithoutAnOrganisationContactTheConfiguredSupportEmailIsUsed(): void
    {
        $this->assertSame('help@shop.test', $this->contactEmail());
    }

    /**
     * A support email still at Magento's shipped placeholder is no contact: the key stays, empty.
     *
     * @return void
     */
    public function testTheContactEmailIsEmptyWhenTheSupportEmailIsTheShippedDefault(): void
    {
        $this->assertSame('', $this->contactEmail());
    }

    /**
     * The manifest's contact_email.
     *
     * @return mixed
     */
    private function contactEmail(): mixed
    {
        $manifest = Bootstrap::getObjectManager()->create(AiPluginBuilder::class)->build();
        $this->assertArrayHasKey('contact_email', $manifest);

        return $manifest['contact_email'];
    }
}
