<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\LlmsTxt;

use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\FaqRepositoryInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\OrganizationRepository;
use PHPUnit\Framework\TestCase;

/**
 * What /llms.txt and /llms-full.txt say, read from the files a rebuild writes.
 *
 * The documents are built through FeedRegenerator, under the store emulation it runs every store
 * view in, and read back from storage: the files served, not just the builder's return value.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class LlmsTxtOutputTest extends TestCase
{
    private const SUPPORT_EMAIL = 'trans_email/ident_support/email';
    private const FAQ_GROUPS    = 'mageos_aeo/llms_txt/faq_groups';

    /**
     * Remove the files the tests wrote, and the Organizations the rolled-back saves left memoised.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->storage()->deleteForStore('llms*', $this->storeId());
        $this->storage()->deleteForStore('.*.tmp', $this->storeId());
        Bootstrap::getObjectManager()->removeSharedInstance(FeedStorage::class);
        Bootstrap::getObjectManager()->get(OrganizationRepository::class)->_resetState();
    }

    /**
     * The locale line carries the store view's configured locale.
     *
     * @return void
     */
    #[Config('general/locale/code', 'en_GB', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheLocaleLineIsTheStoreViewLocale(): void
    {
        [$concise, $full] = $this->build();

        $this->assertStringContainsString("\n- Locale: en_GB\n", $concise);
        $this->assertStringContainsString("\n- Locale: en_GB\n", $full);
    }

    /**
     * The served /llms.txt passes Lighthouse's llms-txt checks (an H1, a markdown link, at least
     * 50 characters), and every line under an H2 is a "- [name](url)" item, as the llms.txt
     * format and its reference parser require.
     *
     * @return void
     */
    public function testTheConciseDocumentFollowsTheLlmsTxtFormat(): void
    {
        [$concise] = $this->build();

        $this->assertMatchesRegularExpression('/^\s*#\s+.+/m', $concise);
        $this->assertMatchesRegularExpression('/\[.+\]\(.+\)/', $concise);
        $this->assertGreaterThanOrEqual(50, \strlen($concise));

        $underH2 = false;
        foreach (explode("\n", $concise) as $line) {
            if (str_starts_with($line, '## ')) {
                $underH2 = true;
                continue;
            }
            if ($underH2 && trim($line) !== '') {
                $this->assertMatchesRegularExpression(
                    '/^\s*-\s*\[[^\]]+\]\([^)\s]+\)(?::\s*.*)?$/',
                    $line,
                    'Only "- [name](url)" items may follow an H2.'
                );
            }
        }
    }

    /**
     * The Organization's contact email is the AI contact, ahead of the store's support email.
     *
     * @return void
     */
    #[Config(self::SUPPORT_EMAIL, 'help@shop.test', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheAiContactIsTheOrganizationContactEmail(): void
    {
        $this->organizationContact('ai@shop.test');

        [$concise, $full] = $this->build();

        $this->assertStringContainsString("- Contact for automated queries: <ai@shop.test>\n", $concise);
        $this->assertStringContainsString("- Contact for automated queries: <ai@shop.test>\n", $full);
        $this->assertStringNotContainsString('help@shop.test', $concise . $full);
    }

    /**
     * Without an Organization contact, the store's configured support email is the AI contact.
     *
     * @return void
     */
    #[Config(self::SUPPORT_EMAIL, 'help@shop.test', ScopeInterface::SCOPE_STORE, 'default')]
    public function testWithoutAnOrganizationContactTheConfiguredSupportEmailIsUsed(): void
    {
        [$concise, $full] = $this->build();

        $this->assertStringContainsString("- Contact for automated queries: <help@shop.test>\n", $concise);
        $this->assertStringContainsString("- Contact for automated queries: <help@shop.test>\n", $full);
    }

    /**
     * A support email still at Magento's shipped placeholder is no contact at all.
     *
     * @return void
     */
    public function testNoAiContactWhenTheSupportEmailIsTheShippedDefault(): void
    {
        [$concise, $full] = $this->build();

        $this->assertStringNotContainsString('Contact for automated queries', $concise . $full);
        $this->assertStringNotContainsString('support@example.com', $concise . $full);
    }

    /**
     * By default the `global` FAQ group is the one included.
     *
     * @return void
     */
    public function testTheGlobalFaqGroupIsIncludedByDefault(): void
    {
        $this->faq('global', 'Do you ship worldwide?');
        $this->faq('shipping', 'How long does delivery take?');

        [$concise, $full] = $this->build();

        foreach ([$concise, $full] as $document) {
            $this->assertStringContainsString("Frequently asked questions:\n", $document);
            $this->assertStringContainsString('- **Do you ship worldwide?** ', $document);
            $this->assertStringNotContainsString('How long does delivery take?', $document);
        }
    }

    /**
     * The configured FAQ groups are included, in the configured order.
     *
     * @return void
     */
    #[Config(self::FAQ_GROUPS, 'global,shipping', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheConfiguredFaqGroupsAreIncluded(): void
    {
        $this->faq('shipping', 'How long does delivery take?');
        $this->faq('global', 'Do you ship worldwide?');

        [$concise, $full] = $this->build();

        foreach ([$concise, $full] as $document) {
            $global   = strpos($document, '**Do you ship worldwide?**');
            $shipping = strpos($document, '**How long does delivery take?**');
            $this->assertIsInt($global, 'The global question is missing.');
            $this->assertIsInt($shipping, 'The shipping question is missing.');
            $this->assertLessThan($shipping, $global, 'The groups are not in the configured order.');
        }
    }

    /**
     * With no group selected, there is no FAQ section.
     *
     * @return void
     */
    #[Config(self::FAQ_GROUPS, '', ScopeInterface::SCOPE_STORE, 'default')]
    public function testNoFaqSectionWhenNoGroupIsSelected(): void
    {
        $this->faq('global', 'Do you ship worldwide?');

        [$concise, $full] = $this->build();

        $this->assertStringNotContainsString('Frequently asked questions:', $concise . $full);
    }

    /**
     * Rebuild the llms documents and return [llms.txt, llms-full.txt].
     *
     * @return string[]
     */
    private function build(): array
    {
        Bootstrap::getObjectManager()->create(FeedRegenerator::class)
            ->regenerate(FeedRegenerator::GROUP_LLMS);

        return [
            (string) $this->storage()->read('llms.txt', $this->storeId()),
            (string) $this->storage()->read('llms-full.txt', $this->storeId()),
        ];
    }

    /**
     * Save the default-scope Organization with a contact point email.
     *
     * @param string $email
     * @return void
     */
    private function organizationContact(string $email): void
    {
        $repository   = Bootstrap::getObjectManager()->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get();
        $organization->setContactPoint(['email' => $email]);
        $repository->save($organization);
    }

    /**
     * Save an active FAQ for every store view.
     *
     * @param string $identifier
     * @param string $question
     * @return void
     */
    private function faq(string $identifier, string $question): void
    {
        /** @var Faq $faq */
        $faq = Bootstrap::getObjectManager()->create(Faq::class);
        $faq->setIdentifier($identifier);
        $faq->setStoreId(0);
        $faq->setQuestion($question);
        $faq->setAnswer('An answer.');
        $faq->setSortOrder(0);
        $faq->setIsActive(true);
        Bootstrap::getObjectManager()->get(FaqRepositoryInterface::class)->save($faq);
    }

    /**
     * The feed storage.
     *
     * @return FeedStorage
     */
    private function storage(): FeedStorage
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class);
    }

    /**
     * ID of the default store view.
     *
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getId();
    }
}
