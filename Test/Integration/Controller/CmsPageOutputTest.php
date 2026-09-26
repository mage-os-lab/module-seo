<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A CMS page's URL in its WebPage node, og:url and canonical — the home page's included.
 *
 * The home page is the request for the store's base URL: its path is empty, the test core's
 * router itself makes (`Framework\App\Router\Base::parseRequest()`). Whichever page
 * `web/default/cms_home_page` names — by identifier or by page ID, as core loads it — is described
 * at that URL. The same action at another path (`/cms/index/index`) is not the home page.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class CmsPageOutputTest extends AbstractController
{
    private const BASE = 'http://localhost/index.php/';

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
     * @return void
     */
    public function testTheHomePageIsDescribedAtTheBaseUrl(): void
    {
        $body = $this->rendered('/');
        $node = $this->webPageNode($body);

        $this->assertSame('WebPage', $node['@type'] ?? null);
        $this->assertSame(self::BASE . '#webpage', $node['@id'] ?? null);
        $this->assertSame(self::BASE, $node['url'] ?? null);
        $this->assertSame(self::BASE, $this->ogUrl($body));
        $this->assertSame(self::BASE, $this->canonical($body));
    }

    /**
     * @return void
     */
    public function testAHomePageSetToAnotherCmsPageIsThatPageAtTheBaseUrl(): void
    {
        $page = $this->page('mageos-seo-home-' . uniqid(), 'MageOS SEO Home');
        $this->homePage($page->getIdentifier());

        $this->assertHomePageIs('MageOS SEO Home', $this->rendered('/'));
    }

    /**
     * core's `ResourceModel\Page` loads a numeric home page setting by page ID.
     *
     * @return void
     */
    public function testAHomePageSetByPageIdIsThatPageAtTheBaseUrl(): void
    {
        $page = $this->page('mageos-seo-home-' . uniqid(), 'MageOS SEO Home');
        $this->homePage((string) $page->getId());

        $this->assertHomePageIs('MageOS SEO Home', $this->rendered('/'));
    }

    /**
     * @return void
     */
    public function testAnOrdinaryCmsPageKeepsItsOwnUrl(): void
    {
        $identifier = 'mageos-seo-page-' . uniqid();
        $page       = $this->page($identifier, 'MageOS SEO Page');

        $body = $this->rendered('cms/page/view/page_id/' . $page->getId());
        $node = $this->webPageNode($body);

        $this->assertSame('WebPage', $node['@type'] ?? null);
        $this->assertSame(self::BASE . $identifier . '#webpage', $node['@id'] ?? null);
        $this->assertSame(self::BASE . $identifier, $node['url'] ?? null);
        $this->assertSame(self::BASE . $identifier, $this->ogUrl($body));
        $this->assertSame(self::BASE . $identifier, $this->canonical($body));
    }

    /**
     * The home action at a path of its own runs the same code with the same content, but the page
     * at that URL is not the home page.
     *
     * @return void
     */
    public function testTheHomeActionAtAnotherPathIsNotTheHomePage(): void
    {
        $body = $this->rendered('cms/index/index');

        $this->assertSame('', $this->canonical($body));
        $this->assertSame([], $this->webPageNode($body));
    }

    /**
     * Assert the page rendered is the named CMS page, described at the store base URL.
     *
     * @param string $title
     * @param string $body
     * @return void
     */
    private function assertHomePageIs(string $title, string $body): void
    {
        $node = $this->webPageNode($body);

        $this->assertSame('WebPage', $node['@type'] ?? null);
        $this->assertSame($title, $node['name'] ?? null);
        $this->assertSame(self::BASE, $node['url'] ?? null);
        $this->assertSame(self::BASE, $this->ogUrl($body));
        $this->assertSame($title, $this->ogTitle($body));
        $this->assertSame(self::BASE, $this->canonical($body));
    }

    /**
     * An active CMS page in every store view.
     *
     * @param string $identifier
     * @param string $title
     * @return PageInterface
     */
    private function page(string $identifier, string $title): PageInterface
    {
        $page = $this->_objectManager->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => $title,
            PageInterface::CONTENT    => '<p>' . $title . '</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        $this->_objectManager->get(PageRepositoryInterface::class)->save($page);
        $this->createdPageIds[] = (int) $page->getId();

        return $page;
    }

    /**
     * Name the default store view's home page, as the admin's Default Pages setting does.
     *
     * @param string $value A page identifier or page ID
     * @return void
     */
    private function homePage(string $value): void
    {
        $this->_objectManager->get(MutableScopeConfigInterface::class)
            ->setValue('web/default/cms_home_page', $value, 'store', 'default');
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
     * The JSON-LD node describing the page: the one whose @id ends in #webpage.
     *
     * The speakable provider adds a WebPage node of its own, without an @id, on every page.
     *
     * @param string $body
     * @return array<string,mixed>
     */
    private function webPageNode(string $body): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            foreach (\is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $node) {
                if (\is_array($node) && str_ends_with((string) ($node['@id'] ?? ''), '#webpage')) {
                    return $node;
                }
            }
        }

        return [];
    }

    /**
     * @param string $body
     * @return string
     */
    private function ogUrl(string $body): string
    {
        return preg_match('#<meta property="og:url"\s+content="([^"]*)"#', $body, $match) === 1
            ? html_entity_decode($match[1])
            : '';
    }

    /**
     * @param string $body
     * @return string
     */
    private function ogTitle(string $body): string
    {
        return preg_match('#<meta property="og:title"\s+content="([^"]*)"#', $body, $match) === 1
            ? html_entity_decode($match[1])
            : '';
    }

    /**
     * The canonical link's URL, or '' when the page has none.
     *
     * @param string $body
     * @return string
     */
    private function canonical(string $body): string
    {
        return preg_match('#<link rel="canonical" href="([^"]*)"#', $body, $match) === 1
            ? html_entity_decode($match[1])
            : '';
    }
}
