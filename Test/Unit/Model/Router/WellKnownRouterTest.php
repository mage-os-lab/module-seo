<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use MageOS\Seo\Model\Router\WellKnownRouter;
use MageOS\Seo\Model\WellKnown\EndpointPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WellKnownRouterTest extends TestCase
{
    private ActionFactory&MockObject $actionFactory;
    private EndpointPool&MockObject $pool;
    private WellKnownRouter $router;

    protected function setUp(): void
    {
        $this->actionFactory = $this->createMock(ActionFactory::class);
        $this->pool = $this->createMock(EndpointPool::class);
        $this->router = new WellKnownRouter($this->actionFactory, $this->pool);
    }

    private function request(string $path, string $module = ''): Http&MockObject
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getModuleName')->willReturn($module);

        return $request;
    }

    public function testNonWellKnownPathReturnsNull(): void
    {
        $this->actionFactory->expects($this->never())->method('create');
        $this->assertNull($this->router->match($this->request('/catalog/product/view')));
    }

    public function testUnregisteredWellKnownPathReturnsNull(): void
    {
        $this->pool->method('has')->with('unknown')->willReturn(false);
        $this->actionFactory->expects($this->never())->method('create');

        $this->assertNull($this->router->match($this->request('/.well-known/unknown')));
    }

    public function testLoopGuardReturnsNullWhenAlreadyDispatched(): void
    {
        $this->actionFactory->expects($this->never())->method('create');
        $this->assertNull($this->router->match($this->request('/.well-known/ucp', 'mageos-agentic')));
    }

    public function testRegisteredPathForwardsToDispatcher(): void
    {
        $this->pool->method('has')->with('ucp')->willReturn(true);
        $request = $this->request('/.well-known/ucp');

        $request->expects($this->once())->method('setModuleName')->with('mageos-agentic')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with('wellknown')->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with('index')->willReturnSelf();
        $request->expects($this->once())->method('setParam')->with('endpoint', 'ucp')->willReturnSelf();
        $request->method('setAlias')->willReturnSelf();

        $action = $this->createMock(ActionInterface::class);
        $this->actionFactory->expects($this->once())->method('create')->willReturn($action);

        $this->assertSame($action, $this->router->match($request));
    }
}
