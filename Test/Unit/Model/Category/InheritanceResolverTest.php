<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category;

use MageOS\Seo\Model\Category\InheritanceResolver;
use PHPUnit\Framework\TestCase;

class InheritanceResolverTest extends TestCase
{
    private InheritanceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new InheritanceResolver();
    }

    public function testTheMostSpecificSourceWins(): void
    {
        $row = $this->resolver->resolve([
            ['robots_meta' => 'INDEX,FOLLOW'],
            ['robots_meta' => 'NOINDEX,FOLLOW'],
        ]);

        $this->assertSame('INDEX,FOLLOW', $row['robots_meta']);
    }

    public function testAFieldIsTakenFromTheNearestSourceThatHasIt(): void
    {
        $row = $this->resolver->resolve([
            ['robots_meta' => null],
            ['robots_meta' => 'NOINDEX,FOLLOW'],
            ['robots_meta' => 'INDEX,NOFOLLOW'],
        ]);

        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta']);
    }

    public function testEachFieldResolvesIndependently(): void
    {
        // The point of the rewrite: a template from the parent and a robots value from the
        // grandparent, which copying one ancestor's whole row could never produce.
        $row = $this->resolver->resolve([
            ['schema_template' => null, 'robots_meta' => null, 'item_list_enabled' => 1],
            ['schema_template' => 'Apparel', 'robots_meta' => null],
            ['schema_template' => 'Generic', 'robots_meta' => 'NOINDEX,FOLLOW'],
        ]);

        $this->assertSame('Apparel', $row['schema_template']);
        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta']);
        $this->assertSame(1, $row['item_list_enabled']);
    }

    public function testInheritanceNoLongerDependsOnATemplateBeingSet(): void
    {
        // Previously the walk only started when an ancestor had a schema_template, so a parent
        // carrying nothing but a robots value was passed over entirely.
        $row = $this->resolver->resolve([
            [],
            ['robots_meta' => 'NOINDEX,FOLLOW'],
        ]);

        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta']);
    }

    public function testZeroIsAValueAndIsNotInheritedOver(): void
    {
        // item_list_enabled = 0 means "switch it off here"; empty() would discard it and inherit
        // the ancestor's 1, giving the merchant the opposite of what they asked for.
        $row = $this->resolver->resolve([
            ['item_list_enabled' => 0],
            ['item_list_enabled' => 1],
        ]);

        $this->assertSame(0, $row['item_list_enabled']);
    }

    public function testAnEmptyStringCountsAsUnset(): void
    {
        $row = $this->resolver->resolve([
            ['schema_template' => ''],
            ['schema_template' => 'Generic'],
        ]);

        $this->assertSame('Generic', $row['schema_template']);
    }

    public function testIdentityColumnsAreNeverInherited(): void
    {
        $row = $this->resolver->resolve([
            ['category_id' => 14, 'store_id' => 2, 'robots_meta' => null],
            ['category_id' => 5, 'store_id' => 0, 'entity_id' => 99, 'updated_at' => 'yesterday'],
        ]);

        $this->assertSame(14, $row['category_id'], 'The row still identifies the category asked for.');
        $this->assertSame(2, $row['store_id']);
        $this->assertArrayNotHasKey('entity_id', $row);
        $this->assertArrayNotHasKey('updated_at', $row);
    }

    public function testAFieldNoSourceSetsStaysAbsent(): void
    {
        // Absent rather than null, so a caller can still tell "not configured" from "configured
        // as nothing".
        $row = $this->resolver->resolve([['robots_meta' => 'INDEX,FOLLOW']]);

        $this->assertArrayNotHasKey('schema_template', $row);
    }

    public function testNoSourcesAtAllResolveToNothing(): void
    {
        $this->assertSame([], $this->resolver->resolve([]));
    }

    public function testEmptySourcesAreSkippedRatherThanTakenAsTheAnswer(): void
    {
        // Most categories in a chain have no row at all; those gaps must not stop the walk.
        $row = $this->resolver->resolve([
            [],
            [],
            ['robots_meta' => 'NOINDEX,FOLLOW'],
        ]);

        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta']);
    }
}
