<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Data\OrganisationInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class OrganisationRepositoryTest extends TestCase
{
    /**
     * @var OrganisationRepositoryInterface
     */
    private OrganisationRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Bootstrap::getObjectManager()->get(OrganisationRepositoryInterface::class);
    }

    public function testGetReturnsOrganisationInstanceWhenTableIsEmpty(): void
    {
        $org = $this->repository->get();
        $this->assertInstanceOf(OrganisationInterface::class, $org);
        $this->assertSame('', $org->getName());
        $this->assertSame('', $org->getUrl());
    }

    public function testOrgTypeDefaultsToOrganizationWhenNoRowExists(): void
    {
        $this->assertSame('Organization', $this->repository->get()->getOrgType());
    }

    public function testSaveAndRetrieveNameAndUrlRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $this->repository->save($org);

        $retrieved = $this->repository->get();
        $this->assertSame('Acme Ltd', $retrieved->getName());
        $this->assertSame('https://acme.com', $retrieved->getUrl());
    }

    public function testSaveOverwritesPreviousValues(): void
    {
        $org = $this->repository->get();
        $org->setName('First');
        $org->setUrl('https://first.com');
        $this->repository->save($org);

        $org2 = $this->repository->get();
        $org2->setName('Second');
        $org2->setUrl('https://second.com');
        $this->repository->save($org2);

        $final = $this->repository->get();
        $this->assertSame('Second', $final->getName());
        $this->assertSame('https://second.com', $final->getUrl());
    }

    public function testDescriptionRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $org->setDescription('Quality widgets since 1900');
        $this->repository->save($org);

        $this->assertSame('Quality widgets since 1900', $this->repository->get()->getDescription());
    }

    public function testOrgTypeRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $org->setOrgType('Corporation');
        $this->repository->save($org);

        $this->assertSame('Corporation', $this->repository->get()->getOrgType());
    }

    public function testLogoFieldsRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $org->setLogoPath('https://acme.com/logo.png');
        $org->setLogoWidth(200);
        $org->setLogoHeight(60);
        $this->repository->save($org);

        $retrieved = $this->repository->get();
        $this->assertSame('https://acme.com/logo.png', $retrieved->getLogoPath());
        $this->assertSame(200, $retrieved->getLogoWidth());
        $this->assertSame(60, $retrieved->getLogoHeight());
    }

    public function testSocialProfilesJsonRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $org->setSocialProfiles([
            'twitter'  => 'https://twitter.com/acme',
            'linkedin' => 'https://linkedin.com/company/acme',
        ]);
        $this->repository->save($org);

        $profiles = $this->repository->get()->getSocialProfiles();
        $this->assertSame('https://twitter.com/acme', $profiles['twitter']);
        $this->assertSame('https://linkedin.com/company/acme', $profiles['linkedin']);
    }

    public function testContactPointJsonRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $org->setContactPoint([
            'contactType' => 'customer support',
            'email'       => 'support@acme.com',
        ]);
        $this->repository->save($org);

        $contactPoint = $this->repository->get()->getContactPoint();
        $this->assertSame('customer support', $contactPoint['contactType']);
        $this->assertSame('support@acme.com', $contactPoint['email']);
    }

    public function testEmptySocialProfilesRoundTrip(): void
    {
        $org = $this->repository->get();
        $org->setName('Acme Ltd');
        $org->setUrl('https://acme.com');
        $org->setSocialProfiles([]);
        $this->repository->save($org);

        $this->assertSame([], $this->repository->get()->getSocialProfiles());
    }
}
