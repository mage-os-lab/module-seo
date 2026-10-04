<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The retired shared frontName `mageos-seo` answers nothing. The documents' own internal URLs are
 * their modules' (MageOS_Aeo's `mageos-aeo`, MageOS_Agentic's `mageos-agentic`).
 *
 * One dispatch per test: resetRequest() leaves the shared response in place.
 *
 * @magentoAppArea frontend
 */
class InternalRoutesTest extends AbstractController
{
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
