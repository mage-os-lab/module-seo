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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CanonicalPathRedirectTest extends TestCase
{
    /**
     * @var Http&Stub
     */
    private Http&Stub $request;

    /**
     * @var RedirectFactory&Stub
     */
    private RedirectFactory&Stub $redirectFactory;

    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var CanonicalPathRedirect
     */
    private CanonicalPathRedirect $model;

    protected function setUp(): void
    {
        $this->request         = $this->createStub(Http::class);
        $this->redirectFactory = $this->createStub(RedirectFactory::class);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->model = $this->model();
    }

    /**
     * The class under test, over the given redirect factory or this test's stub.
     *
     * @param RedirectFactory|null $redirectFactory
     * @return CanonicalPathRedirect
     */
    private function model(?RedirectFactory $redirectFactory = null): CanonicalPathRedirect
    {
        return new CanonicalPathRedirect(
            $this->request,
            $redirectFactory ?? $this->redirectFactory,
            $this->storeManager
        );
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function stubRequest(string $pathInfo, array $queryParams = []): void
    {
        $this->request->method('getPathInfo')->willReturn($pathInfo);
        $query = $this->createStub(Parameters::class);
        $query->method('toArray')->willReturn($queryParams);
        $this->request->method('getQuery')->willReturn($query);
    }

    public function testCanonicalRequestReturnsNull(): void
    {
        $this->stubRequest('/llms.txt', []);
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->expects($this->never())->method('create');

        $this->assertNull($this->model($redirectFactory)->check('llms.txt'));
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

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setUrl')->willReturnSelf();
        $redirect->method('setHttpResponseCode')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->expects($this->once())->method('create')->willReturn($redirect);

        $this->assertSame($redirect, $this->model($redirectFactory)->check('llms.txt'));
    }
}
