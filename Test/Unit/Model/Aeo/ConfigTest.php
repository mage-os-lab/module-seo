<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Aeo;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\Aeo\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The llms documents', feed storage's and AI crawler directives' settings: what each reads, at which
 * scope, and how its raw value becomes the answer.
 */
class ConfigTest extends TestCase
{
    private const STORE_ID = 3;

    /**
     * Every read, as [path, scope type, scope code].
     *
     * @var array<int,array{0:string,1:string,2:mixed}>
     */
    private array $reads = [];

    /**
     * A flag reads true from '1' and false from nothing, at the scope it belongs to.
     *
     * @dataProvider flags
     * @param string $method
     * @param string $path
     * @param mixed[] $arguments
     * @param array{0:string,1:mixed} $scope
     * @return void
     */
    #[DataProvider('flags')]
    public function testAFlag(string $method, string $path, array $arguments, array $scope): void
    {
        $this->assertTrue($this->config([$path => '1'])->$method(...$arguments));
        $this->assertSame([$path, ...$scope], end($this->reads));

        $this->assertFalse($this->config([])->$method(...$arguments));
    }

    /**
     * The flags, the arguments they take and the scope each is read at.
     *
     * @return array<string,array{0:string,1:string,2:mixed[],3:array{0:string,1:mixed}}>
     */
    public static function flags(): array
    {
        $store = [ScopeInterface::SCOPE_STORE, self::STORE_ID];

        return [
            'llms.txt'      => ['isLlmsTxtEnabled', Config::XML_LLMS_ENABLED, [self::STORE_ID], $store],
            'llms-full.txt' => ['isLlmsFullTxtEnabled', Config::XML_LLMS_FULL_ENABLED, [self::STORE_ID], $store],
            'llms.jsonl'    => ['isLlmsJsonlEnabled', Config::XML_LLMS_JSONL_ENABLED, [self::STORE_ID], $store],
            'ai robots'     => [
                'isAiRobotsEnabled',
                Config::XML_AI_ROBOTS_ENABLED,
                [],
                [ScopeInterface::SCOPE_STORE, null],
            ],
        ];
    }

    public function testTheFeedStorageDirectoryIsTrimmedAndReadAtDefaultScope(): void
    {
        $config = $this->config([Config::XML_FEEDS_STORAGE_DIR => " /mnt/feeds \n"]);

        $this->assertSame('/mnt/feeds', $config->getFeedStorageDir());
        $this->assertSame(
            [Config::XML_FEEDS_STORAGE_DIR, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null],
            end($this->reads)
        );
        $this->assertSame('', $this->config([])->getFeedStorageDir());
    }

    public function testTheDisallowedAiBotsAreAListForTheCurrentStore(): void
    {
        $path = Config::XML_AI_ROBOTS_DISALLOWED;

        $this->assertSame(
            ['CCBot', 'Bytespider'],
            $this->config([$path => ' CCBot, ,Bytespider '])->getAiDisallowedBots()
        );
        $this->assertSame([$path, ScopeInterface::SCOPE_STORE, null], end($this->reads));
        $this->assertSame([], $this->config([])->getAiDisallowedBots());
    }

    /**
     * `llms_txt/faq_groups`: the FAQ groups the llms documents include, stored as a multi-select's
     * comma-separated list. None selected must read as none, not as a default.
     *
     * @return void
     */
    public function testTheFaqGroupsAreTheSelectedOnesInOrder(): void
    {
        $path = Config::XML_LLMS_FAQ_GROUPS;

        $this->assertSame(['global', 'shipping'], $this->config([$path => 'global,shipping'])->getLlmsFaqGroups(1));
        $this->assertSame([$path, ScopeInterface::SCOPE_STORE, 1], end($this->reads));
        $this->assertSame(['global', 'returns'], $this->config([$path => ' global, ,returns '])->getLlmsFaqGroups(1));
        $this->assertSame([], $this->config([$path => ''])->getLlmsFaqGroups(1));
        $this->assertSame([], $this->config([])->getLlmsFaqGroups(1));
    }

    /**
     * Config over the given values by path, recording every read in $this->reads.
     *
     * @param array<string,string> $values
     * @return Config
     */
    private function config(array $values): Config
    {
        $this->reads = [];
        $read = function (
            $path,
            $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $scopeCode = null
        ) use ($values) {
            $this->reads[] = [$path, $scopeType, $scopeCode];

            return $values[$path] ?? null;
        };

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback($read);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (...$arguments): bool => (bool) $read(...$arguments)
        );

        return new Config($scopeConfig);
    }
}
