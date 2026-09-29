<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use MageOS\Seo\Model\Router\LlmsTxtRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LlmsTxtRouterTest extends TestCase
{
    /**
     * @var ActionFactory&MockObject
     */
    private ActionFactory&MockObject $actionFactory;

    /**
     * @var LlmsTxtRouter
     */
    private LlmsTxtRouter $router;

    protected function setUp(): void
    {
        $this->actionFactory = $this->createMock(ActionFactory::class);
        $this->router        = new LlmsTxtRouter($this->actionFactory);
    }

    private function request(string $path, string $module = ''): Http&MockObject
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getModuleName')->willReturn($module);

        return $request;
    }

    public function testNonMatchingPathReturnsNull(): void
    {
        $this->actionFactory->expects($this->never())->method('create');
        $this->assertNull($this->router->match($this->request('/catalog/product/view')));
    }

    public function testLoopGuardReturnsNullWhenAlreadyDispatched(): void
    {
        // Path matches, but the module is already ours — already forwarded.
        $this->actionFactory->expects($this->never())->method('create');
        $this->assertNull($this->router->match($this->request('/llms.txt', 'mageos-aeo')));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'llms.txt'      => ['/llms.txt', 'llms'],
            'llms-full.txt' => ['/llms-full.txt', 'llmsfull'],
            'llms.jsonl'    => ['/llms.jsonl', 'llmsjsonl'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    #[DataProvider('routeProvider')]
    public function testMatchedPathForwardsToController(string $path, string $expectedController): void
    {
        $request = $this->request($path);
        $request->expects($this->once())->method('setModuleName')->with('mageos-aeo')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with($expectedController)->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with('index')->willReturnSelf();
        $request->method('setAlias')->willReturnSelf();

        $action = $this->createMock(ActionInterface::class);
        $this->actionFactory->expects($this->once())->method('create')->willReturn($action);

        $this->assertSame($action, $this->router->match($request));
    }
}
