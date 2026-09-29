<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Model\Config\Source\OrgType;
use PHPUnit\Framework\TestCase;

/**
 * The organization types offered in the admin, each emitted as the Organization node's @type.
 */
class OrgTypeTest extends TestCase
{
    public function testEveryOptionIsASchemaOrgTypeName(): void
    {
        $this->assertSame(
            [
                'Organization',
                'Corporation',
                'LocalBusiness',
                'NGO',
                'EducationalOrganization',
                'GovernmentOrganization',
            ],
            array_column((new OrgType())->toOptionArray(), 'value')
        );
    }
}
