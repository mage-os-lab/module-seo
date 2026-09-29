<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Router;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Stdlib\Parameters;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Router\CanonicalPathRedirect;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CanonicalPathRedirectTest extends TestCase
{
    /**
     * @var Http&MockObject
     */
    private Http&MockObject $request;

    /**
     * @var RedirectFactory&MockObject
     */
    private RedirectFactory&MockObject $redirectFactory;

    /**
     * @var CanonicalPathRedirect
     */
    private CanonicalPathRedirect $model;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(Http::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->model = new CanonicalPathRedirect($this->request, $this->redirectFactory, $storeManager);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function stubRequest(string $pathInfo, array $queryParams = []): void
    {
        $this->request->method('getPathInfo')->willReturn($pathInfo);
        $query = $this->createMock(Parameters::class);
        $query->method('toArray')->willReturn($queryParams);
        $this->request->method('getQuery')->willReturn($query);
    }

    public function testCanonicalRequestReturnsNull(): void
    {
        $this->stubRequest('/llms.txt', []);
        $this->redirectFactory->expects($this->never())->method('create');

        $this->assertNull($this->model->check('llms.txt'));
    }

    public function testQueryStringTriggers301ToCanonicalPath(): void
    {
        $this->stubRequest('/llms.txt', ['utm_source' => 'x']);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())->method('setUrl')->with('https://example.com/llms.txt')->willReturnSelf();
        $redirect->expects($this->once())->method('setHttpResponseCode')->with(301)->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($redirect);

        $this->assertSame($redirect, $this->model->check('llms.txt'));
    }

    public function testInternalControllerUrlTriggers301(): void
    {
        // The standard-router URL is a duplicate of the canonical path.
        $this->stubRequest('/mageos-aeo/llms/index', []);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setUrl')->willReturnSelf();
        $redirect->method('setHttpResponseCode')->willReturnSelf();
        $this->redirectFactory->expects($this->once())->method('create')->willReturn($redirect);

        $this->assertSame($redirect, $this->model->check('llms.txt'));
    }
}
