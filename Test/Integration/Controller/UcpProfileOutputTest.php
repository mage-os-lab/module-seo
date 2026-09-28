<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Seo\Model\Ucp\UcpConfig;

/**
 * /.well-known/ucp as served: a UCP 2026-08-25 business profile, answered without a redirect and with
 * the caching the spec requires (`public`, max-age of at least 60, never private/no-store/no-cache).
 *
 * The session's own `Pragma: no-cache` is sent by PHP's header(), not the response object, so it is
 * out of a dispatch's sight; NoSessionForPublicFeeds keeps it off, and a live request confirms it.
 *
 * @magentoAppArea frontend
 */
class UcpProfileOutputTest extends AbstractController
{
    /**
     * A stored value from the removed capability toggles must not bring an advertisement back.
     *
     * @return void
     */
    #[Config(UcpConfig::XML_UCP_ENABLED, '1', ScopeInterface::SCOPE_STORE, 'default')]
    #[Config('mageos_seo_ucp/capabilities/checkout', '1', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheProfileDeclaresOnlyWhatIsRegistered(): void
    {
        $this->assertSame(
            '{"ucp":{"version":"2026-08-25","services":{},"capabilities":{},"payment_handlers":{}}}',
            $this->profile()
        );
    }

    /**
     * @return void
     */
    #[Config(UcpConfig::XML_UCP_ENABLED, '1', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheProfileIsCacheableAsTheSpecRequires(): void
    {
        $this->profile();

        /** @var \Magento\Framework\App\Response\Http $response */
        $response = $this->getResponse();
        $this->assertStringStartsWith('application/json', $response->getHeader('Content-Type')->getFieldValue());

        $cacheControl = $response->getHeader('Cache-Control')->getFieldValue();
        $this->assertMatchesRegularExpression('/(^|,)\s*public\s*(,|$)/', $cacheControl);
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cacheControl);
        preg_match('/max-age=(\d+)/', $cacheControl, $maxAge);
        $this->assertGreaterThanOrEqual(60, (int) $maxAge[1]);
        $this->assertDoesNotMatchRegularExpression('/private|no-store|no-cache/', $cacheControl);
    }

    /**
     * @return void
     */
    public function testWhenDisabledThereIsNoProfile(): void
    {
        $this->dispatch('/.well-known/ucp');

        $this->assertSame(404, $this->getResponse()->getHttpResponseCode());
    }

    /**
     * Fetch /.well-known/ucp, asserting it answered directly: UCP forbids a redirect on the profile.
     *
     * @return string
     */
    private function profile(): string
    {
        $this->dispatch('/.well-known/ucp');

        /** @var \Magento\Framework\App\Response\Http $response */
        $response = $this->getResponse();
        $location = $response->getHeader('Location');
        $this->assertSame(
            200,
            $response->getHttpResponseCode(),
            '/.well-known/ucp did not answer directly'
            . ($location ? ': redirected to ' . $location->getFieldValue() : '') . '.'
        );

        return (string) $response->getBody();
    }
}
