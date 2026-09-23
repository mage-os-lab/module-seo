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
            ['value' => '',                 'label' => (string) __('Use Magento Default (no override)')],
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
}
