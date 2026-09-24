<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Plugin\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;
use MageOS\Seo\Model\Router\PublicPaths;
use MageOS\Seo\Plugin\Session\NoSessionForPublicFeeds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review finding S6: a session cookie on a response meant to be shared-cached for a day.
 */
class NoSessionForPublicFeedsTest extends TestCase
{
    /**
     * @param string $path
     * @return void
     *
     * @dataProvider publicPaths
     */
    #[DataProvider('publicPaths')]
    public function testThePublicEndpointsGetNoSession(string $path): void
    {
        $this->assertFalse($this->plugin($path)->afterCheck($this->checker(), true), $path);
    }

    /**
     * @param string $path
     * @return void
     *
     * @dataProvider ordinaryPaths
     */
    #[DataProvider('ordinaryPaths')]
    public function testEveryOtherRequestIsLeftAlone(string $path): void
    {
        $this->assertTrue($this->plugin($path)->afterCheck($this->checker(), true), $path);
    }

    public function testADecisionAlreadyMadeAgainstStartingIsNotOverturned(): void
    {
        // Core refuses the session under CLI; a feed path must not turn that back on.
        $this->assertFalse($this->plugin('/customer/account/login')->afterCheck($this->checker(), false));
    }

    /**
     * @return array<string, string[]>
     */
    public static function publicPaths(): array
    {
        return [
            'llms.txt'            => ['/llms.txt'],
            'llms-full.txt'       => ['/llms-full.txt'],
            'llms.jsonl'          => ['/llms.jsonl'],
            'well-known document' => ['/.well-known/security.txt'],
            'well-known root'     => ['/.well-known/ucp'],
            'internal route'      => ['/mageos-seo/llms/index'],
            'no leading slash'    => ['llms.txt'],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    public static function ordinaryPaths(): array
    {
        return [
            'home'              => ['/'],
            'category'          => ['/gear/bags.html'],
            'customer account'  => ['/customer/account/login'],
            'checkout'          => ['/checkout/cart'],
            // Near misses: a longer name that merely starts the same way is someone else's page.
            'llms-like product' => ['/llms.txt.html'],
            'core sitemap'      => ['/sitemap.xml'],
            // Retired: nothing serves the hreflang sitemap any more, so it is an ordinary 404.
            'retired index'     => ['/hreflang-sitemap.xml'],
            'retired chunk'     => ['/hreflang-sitemap-2.xml'],
        ];
    }

    /**
     * @param string $path
     * @return NoSessionForPublicFeeds
     */
    private function plugin(string $path): NoSessionForPublicFeeds
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($path);

        return new NoSessionForPublicFeeds($request, new PublicPaths());
    }

    /**
     * @return SessionStartChecker
     */
    private function checker(): SessionStartChecker
    {
        return $this->createStub(SessionStartChecker::class);
    }
}
