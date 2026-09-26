<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Speakable sits on the page's own node — Google: "Speakable is used by the Article or Webpage
 * object" — never on an anonymous WebPage of its own.
 *
 * A CMS page's WebPage, a category's CollectionPage, and on a product page a WebPage for the product
 * (a Product can't carry it) whose mainEntity is the product node.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class SpeakableOutputTest extends AbstractController
{
    private const BASE = 'http://localhost/index.php/';

    private const PRODUCT_URL = self::BASE . 'mageos-seo-speakable.html';

    /**
     * The spec the default selectors in config.xml give.
     */
    private const SPEC = [
        '@type'       => 'SpeakableSpecification',
        'cssSelector' => ['.page-title', '.product.attribute.overview', '.category-description'],
    ];

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
        foreach ($this->createdPageIds ?? [] as $pageId) {
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
     * @magentoConfigFixture current_store mageos_seo_general/aeo/speakable_enabled 1
     * @return void
     */
    public function testACmsPageCarriesItOnItsOwnWebPage(): void
    {
        $identifier = 'mageos-seo-speakable-' . uniqid();
        $body       = $this->rendered('cms/page/view/page_id/' . $this->page($identifier)->getId());

        $carriers = $this->nodesWithSpeakable($body);
        $this->assertCount(1, $carriers, 'One node carries speakable.');
        $this->assertSame(self::BASE . $identifier . '#webpage', $carriers[0]['@id'] ?? null);
        $this->assertSame(self::SPEC, $carriers[0]['speakable']);
        $this->assertSame([], $this->anonymousWebPages($body));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/aeo/speakable_enabled 1
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testACategoryCarriesItOnItsCollectionPage(): void
    {
        $body = $this->rendered('catalog/category/view/id/' . $this->fixtureId('category'));

        $carriers = $this->nodesWithSpeakable($body);
        $this->assertCount(1, $carriers, 'One node carries speakable.');
        $this->assertSame('CollectionPage', $carriers[0]['@type'] ?? null);
        $this->assertStringEndsWith('#collectionpage', (string) ($carriers[0]['@id'] ?? ''));
        $this->assertSame(self::SPEC, $carriers[0]['speakable']);
        $this->assertSame([], $this->anonymousWebPages($body));
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/aeo/speakable_enabled 1
     * @return void
     */
    #[DataFixture(
        ProductFixture::class,
        ['sku' => 'mageos-seo-speakable', 'name' => 'MageOS SEO Speakable', 'url_key' => 'mageos-seo-speakable'],
        'product'
    )]
    public function testAProductPageCarriesItOnAWebPageForTheProduct(): void
    {
        $body = $this->rendered('catalog/product/view/id/' . $this->fixtureId('product'));

        $carriers = $this->nodesWithSpeakable($body);
        $this->assertCount(1, $carriers, 'One node carries speakable.');
        $this->assertSame(
            [
                '@context'   => 'https://schema.org',
                '@type'      => 'WebPage',
                '@id'        => self::PRODUCT_URL . '#webpage',
                'url'        => self::PRODUCT_URL,
                'name'       => 'MageOS SEO Speakable',
                'mainEntity' => ['@id' => self::PRODUCT_URL . '#product'],
                'speakable'  => self::SPEC,
            ],
            $carriers[0]
        );
        $this->assertContains(
            self::PRODUCT_URL . '#product',
            array_column($this->nodes($body), '@id'),
            'The mainEntity is the product node on the page.'
        );
    }

    /**
     * A guard: off by default.
     *
     * @return void
     */
    public function testACmsPageCarriesNothingWhenItIsOff(): void
    {
        $body = $this->rendered('cms/page/view/page_id/' . $this->page('mageos-seo-speakable-' . uniqid())->getId());

        $this->assertSame([], $this->nodesWithSpeakable($body));
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testACategoryCarriesNothingWhenItIsOff(): void
    {
        $body = $this->rendered('catalog/category/view/id/' . $this->fixtureId('category'));

        $this->assertSame([], $this->nodesWithSpeakable($body));
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, ['url_key' => 'mageos-seo-speakable'], 'product')]
    public function testAProductPageHasNoWebPageNodeWhenItIsOff(): void
    {
        $body = $this->rendered('catalog/product/view/id/' . $this->fixtureId('product'));

        $this->assertSame([], $this->nodesWithSpeakable($body));
        $this->assertSame(
            [],
            array_filter($this->nodes($body), static fn (array $node): bool => ($node['@type'] ?? null) === 'WebPage')
        );
    }

    /**
     * An active CMS page in every store view.
     *
     * @param string $identifier
     * @return PageInterface
     */
    private function page(string $identifier): PageInterface
    {
        $page = $this->_objectManager->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO Speakable',
            PageInterface::CONTENT    => '<p>Speakable</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        $this->_objectManager->get(PageRepositoryInterface::class)->save($page);
        $this->createdPageIds[] = (int) $page->getId();

        return $page;
    }

    /**
     * @param string $name
     * @return int
     */
    private function fixtureId(string $name): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($name)->getId();
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
     * Every top-level JSON-LD node on the page.
     *
     * @param string $body
     * @return array<int,array<string,mixed>>
     */
    private function nodes(string $body): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);
        $nodes = [];
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            foreach (\is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $node) {
                if (\is_array($node)) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param string $body
     * @return array<int,array<string,mixed>>
     */
    private function nodesWithSpeakable(string $body): array
    {
        return array_values(array_filter(
            $this->nodes($body),
            static fn (array $node): bool => \array_key_exists('speakable', $node)
        ));
    }

    /**
     * WebPage nodes with no @id: nothing can refer to them, or tell which page they describe.
     *
     * @param string $body
     * @return array<int,array<string,mixed>>
     */
    private function anonymousWebPages(string $body): array
    {
        return array_values(array_filter(
            $this->nodes($body),
            static fn (array $node): bool => ($node['@type'] ?? null) === 'WebPage' && !isset($node['@id'])
        ));
    }
}
