<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The standard-router URLs behind the served documents: each owner's frontName answers with a 301 to
 * the document's canonical path, and the retired shared frontName answers nothing.
 *
 * One dispatch per test: resetRequest() leaves the shared response in place.
 *
 * @magentoAppArea frontend
 */
class InternalRoutesTest extends AbstractController
{
    /**
     * @return void
     */
    public function testTheLlmsDocumentsInternalUrlRedirectsToItsCanonicalPath(): void
    {
        $this->dispatch('mageos-aeo/llms/index');

        $this->assertSame(301, $this->getResponse()->getHttpResponseCode());
        $this->assertRedirect($this->stringEndsWith('/llms.txt'));
    }

    /**
     * @return void
     */
    public function testAWellKnownDocumentsInternalUrlRedirectsToItsCanonicalPath(): void
    {
        $this->dispatch('mageos-agentic/wellknown/index/endpoint/ucp');

        $this->assertSame(301, $this->getResponse()->getHttpResponseCode());
        $this->assertRedirect($this->stringEndsWith('/.well-known/ucp'));
    }

    /**
     * @dataProvider retiredUrls
     * @param string $url
     * @return void
     */
    #[DataProvider('retiredUrls')]
    public function testTheSharedFrontNameIsGone(string $url): void
    {
        $this->dispatch($url);

        $this->assert404NotFound();
    }

    /**
     * @return array<string, string[]>
     */
    public static function retiredUrls(): array
    {
        return [
            'llms'       => ['mageos-seo/llms/index'],
            'well-known' => ['mageos-seo/wellknown/index/endpoint/ucp'],
        ];
    }
}
