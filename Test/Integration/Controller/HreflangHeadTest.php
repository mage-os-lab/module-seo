<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Cms\ConfigRepository;
use MageOS\Seo\Model\Config;

/**
 * F4: a page declares its hreflang set in its head only when the set includes the page itself.
 *
 * Google discards a set that does not list the URL it appears on. Two ways a page used to declare
 * one anyway: a store view excluded from hreflang has no link of its own, yet its pages listed every
 * other store view; and of two translations assigned to one store view, the one its group does not
 * name declared the other as its own-language version. The sitemap already held back such sets;
 * these render the page to show the head now does too.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class HreflangHeadTest extends AbstractController
{
    private const GERMAN_HOST = 'http://de.localhost/';
    private const FRENCH_HOST = 'http://fr.localhost/';

    /**
     * IDs of the CMS pages created by the running test.
     *
     * @var int[]|null
     */
    private ?array $createdPageIds = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $pageRepository = $this->_objectManager->get(PageRepositoryInterface::class);
        foreach ($this->createdPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (\Exception) {
                // Already gone.
            }
        }
        $this->createdPageIds = [];

        parent::tearDown();
    }

    /**
     * The German and French store views still link to one another; the excluded English one, which
     * none of them links to, must not claim the pair as its alternates.
     *
     * Three store views, because with two the set left after the exclusion has one store view in it,
     * and a one-store set is never declared at all — the test would pass without the rule.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(StoreFixture::class, as: 'french')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAnExcludedStoreViewsPagesDeclareNoAlternates(): void
    {
        $this->threeLanguagesWithoutEnglish();

        $this->assertSame([], $this->alternates($this->productPage()));
    }

    /**
     * The same set, rendered in a store view that is in it, is declared — so the test above is not
     * passing because nothing renders.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    #[DataFixture(StoreFixture::class, as: 'french')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAStoreViewInTheSetDeclaresIt(): void
    {
        $this->threeLanguagesWithoutEnglish();

        // The test request's host is always "localhost", so core would redirect it to the German
        // host before rendering — on a response of its own, leaving the test's empty at 200.
        $this->_objectManager->get(MutableScopeConfigInterface::class)
            ->setValue('web/url/redirect_to_base', '0', 'store', $this->code('german'));
        $this->_objectManager->get(StoreManagerInterface::class)->setCurrentStore($this->code('german'));

        $alternates = $this->alternates($this->productPage());

        $this->assertStringStartsWith(self::GERMAN_HOST, $alternates['de-DE'] ?? '');
        $this->assertStringStartsWith(self::FRENCH_HOST, $alternates['fr-FR'] ?? '');
        $this->assertArrayNotHasKey('en-US', $alternates);
    }

    /**
     * Of two translations assigned to one store view, the group's page for that store view (the lower
     * page ID) declares the group.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    public function testTheGroupsPageForAStoreViewDeclaresTheGroup(): void
    {
        [$representative] = $this->twoTranslationsInOneStoreView();

        $alternates = $this->alternates($this->cmsPage($representative));

        $this->assertStringStartsWith(self::GERMAN_HOST, $alternates['de-DE'] ?? '');
    }

    /**
     * The other translation declares nothing, rather than naming the representative as its own
     * English version.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'german')]
    public function testTheOtherTranslationInThatStoreViewDeclaresNothing(): void
    {
        [, $other] = $this->twoTranslationsInOneStoreView();

        $this->assertSame([], $this->alternates($this->cmsPage($other)));
    }

    /**
     * The default store view in en-US and excluded; the fixture ones in German and French, each on
     * its own host.
     *
     * @return void
     */
    private function threeLanguagesWithoutEnglish(): void
    {
        $this->inLanguage('german', 'de-DE', self::GERMAN_HOST);
        $this->inLanguage('french', 'fr-FR', self::FRENCH_HOST);

        $defaultStoreId = (int) $this->_objectManager->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();
        $this->_objectManager->get(MutableScopeConfigInterface::class)
            ->setValue(Config::XML_HREFLANG_EXCLUDED_STORES, (string) $defaultStoreId, 'default');
    }

    /**
     * Two translations of one group in the default store view and a third in German; returns the
     * default store view's two page IDs, the group's page for it first.
     *
     * @return int[]
     */
    private function twoTranslationsInOneStoreView(): array
    {
        $this->inLanguage('german', 'de-DE', self::GERMAN_HOST);

        $defaultStoreId = (int) $this->_objectManager->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();
        $group = 'mageos-seo-head-' . uniqid();

        $representative = $this->page([$defaultStoreId], $group);
        $other          = $this->page([$defaultStoreId], $group);
        $this->page([(int) DataFixtureStorageManager::getStorage()->get('german')->getId()], $group);

        return [$representative, $other];
    }

    /**
     * Give a fixture store view a language and its own host.
     *
     * @param string $fixture
     * @param string $code
     * @param string $host
     * @return void
     */
    private function inLanguage(string $fixture, string $code, string $host): void
    {
        $config    = $this->_objectManager->get(MutableScopeConfigInterface::class);
        $storeCode = $this->code($fixture);

        $config->setValue(Config::XML_HREFLANG_CODES, $code, 'store', $storeCode);
        $config->setValue('web/unsecure/base_url', $host, 'store', $storeCode);
        $config->setValue('web/unsecure/base_link_url', $host, 'store', $storeCode);
    }

    /**
     * @param string $fixture
     * @return string
     */
    private function code(string $fixture): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixture)->getCode();
    }

    /**
     * A CMS page in the given store views and translation group; returns its ID.
     *
     * @param int[] $storeIds
     * @param string $group
     * @return int
     */
    private function page(array $storeIds, string $group): int
    {
        $page = $this->_objectManager->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => 'mageos-seo-head-' . uniqid(),
            PageInterface::TITLE      => 'MageOS SEO head translation',
            PageInterface::CONTENT    => '<p>Translation</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => $storeIds,
        ]);
        $this->_objectManager->get(PageRepositoryInterface::class)->save($page);

        $pageId                 = (int) $page->getId();
        $this->createdPageIds[] = $pageId;
        $this->_objectManager->get(ConfigRepository::class)->save($pageId, ['hreflang_group' => $group]);

        return $pageId;
    }

    /**
     * Render the fixture product's page and return its HTML.
     *
     * @return string
     */
    private function productPage(): string
    {
        return $this->rendered(
            'catalog/product/view/id/' . DataFixtureStorageManager::getStorage()->get('product')->getId()
        );
    }

    /**
     * Render a CMS page and return its HTML.
     *
     * @param int $pageId
     * @return string
     */
    private function cmsPage(int $pageId): string
    {
        return $this->rendered('cms/page/view/page_id/' . $pageId);
    }

    /**
     * Dispatch the URI and return the page, failing with the status if it did not render.
     *
     * @param string $uri
     * @return string
     */
    private function rendered(string $uri): string
    {
        $this->dispatch($uri);

        /** @var \Magento\Framework\App\Response\Http $response */
        $response = $this->getResponse();
        $location = $response->getHeader('Location');
        $body     = (string) $response->getBody();
        $this->assertSame(
            200,
            $response->getHttpResponseCode(),
            $uri . ' did not render' . ($location ? ': redirected to ' . $location->getFieldValue() : '') . '.'
        );
        $this->assertStringContainsString('</head>', $body, $uri . ' rendered no page: ' . substr($body, 0, 2000));

        return $body;
    }

    /**
     * The head's alternates, hreflang => href.
     *
     * @param string $body
     * @return array<string,string>
     */
    private function alternates(string $body): array
    {
        preg_match_all('#<link rel="alternate" hreflang="([^"]+)"\s+href="([^"]+)"/>#', $body, $links);

        return array_combine($links[1], array_map('html_entity_decode', $links[2]));
    }
}
