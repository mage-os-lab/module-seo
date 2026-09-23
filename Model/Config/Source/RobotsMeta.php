<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The robots directives offered wherever this module lets one be chosen.
 *
 * Every combination of index, follow and archive is listed. `noarchive` used to appear only
 * beside NOINDEX, which left "index this page but do not keep a cached copy" — a normal request
 * for pages whose content changes, and one MageOS_MetaRobotsTag can express with its independent
 * no_archive flag — impossible to ask for here.
 *
 * The first option, the empty value, means "no directive here" — and what that falls back to
 * depends on where it is chosen. This class is the store-level wording, used by the configuration
 * defaults, where an empty value leaves Magento's Design → Search Engine Robots in charge. The
 * product, category and CMS page forms each use a subclass under RobotsMeta\ that says what an
 * empty value falls back to there. Each form lists exactly one empty option.
 */
class RobotsMeta implements OptionSourceInterface
{
    /**
     * Return robots meta options for system.xml select fields.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '',                 'label' => $this->emptyLabel()],
            ['value' => 'INDEX,FOLLOW',     'label' => 'INDEX, FOLLOW'],
            ['value' => 'NOINDEX,FOLLOW',   'label' => 'NOINDEX, FOLLOW'],
            ['value' => 'INDEX,NOFOLLOW',   'label' => 'INDEX, NOFOLLOW'],
            ['value' => 'NOINDEX,NOFOLLOW', 'label' => 'NOINDEX, NOFOLLOW'],
            [
                'value' => 'INDEX,FOLLOW,max-image-preview:large,max-snippet:-1',
                'label' => 'INDEX, FOLLOW (rich previews: max-image-preview:large, max-snippet:-1)',
            ],
            ['value' => 'INDEX,FOLLOW,noarchive',     'label' => 'INDEX, FOLLOW, noarchive'],
            ['value' => 'INDEX,NOFOLLOW,noarchive',   'label' => 'INDEX, NOFOLLOW, noarchive'],
            ['value' => 'NOINDEX,FOLLOW,noarchive',   'label' => 'NOINDEX, FOLLOW, noarchive'],
            ['value' => 'NOINDEX,NOFOLLOW,noarchive', 'label' => 'NOINDEX, NOFOLLOW, noarchive'],
            [
                'value' => 'NOINDEX,NOFOLLOW,noai,noimageai',
                'label' => 'NOINDEX, NOFOLLOW, noai, noimageai (block AI training)',
            ],
        ];
    }

    /**
     * What choosing no directive here means.
     *
     * Translated when the options are rendered, not when the object is built, so the admin user's
     * locale applies.
     *
     * @return string
     */
    protected function emptyLabel(): string
    {
        return (string) __('Use Magento Default (no override)');
    }
}
