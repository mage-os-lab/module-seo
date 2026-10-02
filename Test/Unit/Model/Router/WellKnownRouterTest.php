<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use MageOS\Seo\Model\Router\WellKnownRouter;
use MageOS\Seo\Model\WellKnown\EndpointPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class WellKnownRouterTest extends TestCase
{
    private ActionFactory&MockObject $actionFactory;
    private EndpointPool&Stub $pool;
    private WellKnownRouter $router;

    protected function setUp(): void
    {
        $this->actionFactory = $this->createMock(ActionFactory::class);
        $this->pool = $this->createStub(EndpointPool::class);
        $this->router = $this->router();
    }

    /**
     * The router under test, over the given endpoint pool or this test's stub.
     *
     * @param EndpointPool|null $pool
     * @return WellKnownRouter
     */
    private function router(?EndpointPool $pool = null): WellKnownRouter
    {
        return new WellKnownRouter($this->actionFactory, $pool ?? $this->pool);
    }

    private function request(string $path, string $module = ''): Http&Stub
    {
        $request = $this->createStub(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getModuleName')->willReturn($module);

        return $request;
    }

    /**
     * The same request as a mock, for a test that verifies how the router rewrites it.
     *
     * @param string $path
     * @return Http&MockObject
     */
    private function requestMock(string $path): Http&MockObject
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getModuleName')->willReturn('');

        return $request;
    }

    public function testNonWellKnownPathReturnsNull(): void
    {
        $this->actionFactory->expects($this->never())->method('create');
        $this->assertNull($this->router->match($this->request('/catalog/product/view')));
    }

    public function testUnregisteredWellKnownPathReturnsNull(): void
    {
        $pool = $this->createMock(EndpointPool::class);
        $pool->method('has')->with('unknown')->willReturn(false);
        $this->actionFactory->expects($this->never())->method('create');

        $this->assertNull($this->router($pool)->match($this->request('/.well-known/unknown')));
    }

    public function testLoopGuardReturnsNullWhenAlreadyDispatched(): void
    {
        $this->actionFactory->expects($this->never())->method('create');
        $this->assertNull($this->router->match($this->request('/.well-known/ucp', 'mageos-agentic')));
    }

    public function testRegisteredPathForwardsToDispatcher(): void
    {
        $pool = $this->createMock(EndpointPool::class);
        $pool->method('has')->with('ucp')->willReturn(true);
        $request = $this->requestMock('/.well-known/ucp');

        $request->expects($this->once())->method('setModuleName')->with('mageos-agentic')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with('wellknown')->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with('index')->willReturnSelf();
        $request->expects($this->once())->method('setParam')->with('endpoint', 'ucp')->willReturnSelf();
        $request->method('setAlias')->willReturnSelf();

        $action = $this->createStub(ActionInterface::class);
        $this->actionFactory->expects($this->once())->method('create')->willReturn($action);

        $this->assertSame($action, $this->router($pool)->match($request));
    }
}
