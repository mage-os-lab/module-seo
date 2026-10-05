<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use MageOS\Seo\Model\Rebuild\CronExpressionMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A cron field is matched by core's schedule model, built once.
 *
 * The schedule factory is Magento's generated class, so this test needs an installation to have
 * generated it. The mutation-testing run works from the module directory alone and excludes this
 * group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class CronExpressionMatcherTest extends TestCase
{
    public function testAFieldIsMatchedAsCoreMatchesIt(): void
    {
        $factory = $this->createMock(ScheduleFactory::class);
        $factory->expects($this->once())->method('create')
            ->willReturn((new \ReflectionClass(Schedule::class))->newInstanceWithoutConstructor());
        $matcher = new CronExpressionMatcher($factory);

        $this->assertTrue($matcher->matches('*/15', 45));
        $this->assertFalse($matcher->matches('*/15', 50));
        $this->assertTrue($matcher->matches('mon-fri', 5));
    }
}
