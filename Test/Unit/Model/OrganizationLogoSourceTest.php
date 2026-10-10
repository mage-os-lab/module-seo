<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model;

use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Model\Organization;
use PHPUnit\Framework\TestCase;

/**
 * Issue #37: where the logo comes from is stored, and a record saved before it was keeps
 * publishing what it published.
 */
class OrganizationLogoSourceTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheStoredSourceIsReturned(): void
    {
        $organization = $this->organization();
        $organization->setLogoPath('mage-os/seo/logo/square.png');

        $organization->setLogoSource(OrganizationInterface::LOGO_SOURCE_DESIGN);
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_DESIGN, $organization->getLogoSource());

        $organization->setLogoSource(OrganizationInterface::LOGO_SOURCE_CUSTOM);
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_CUSTOM, $organization->getLogoSource());
    }

    /**
     * Saved before the source was stored: custom with a logo path, which is what it published, and
     * design without one.
     *
     * @return void
     */
    public function testARecordWithNoSourceIsCustomWithAPathAndDesignWithout(): void
    {
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_DESIGN, $this->organization()->getLogoSource());

        $withPath = $this->organization();
        $withPath->setLogoPath('https://cdn.example/brand.png');
        $this->assertSame(OrganizationInterface::LOGO_SOURCE_CUSTOM, $withPath->getLogoSource());

        $tampered = $this->organization();
        $tampered->setLogoPath('https://cdn.example/brand.png');
        $tampered->setData(OrganizationInterface::LOGO_SOURCE, 'tampered');
        $this->assertSame(
            OrganizationInterface::LOGO_SOURCE_CUSTOM,
            $tampered->getLogoSource(),
            'A stored value that is neither counts as none.'
        );
    }

    /**
     * @return void
     */
    public function testAnyOtherSourceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The logo source must be "design" or "custom", not "theme".');

        $this->organization()->setLogoSource('theme');
    }

    /**
     * @return Organization
     */
    private function organization(): Organization
    {
        return (new \ReflectionClass(Organization::class))->newInstanceWithoutConstructor();
    }
}
