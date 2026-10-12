<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Organization;

use MageOS\Seo\Model\Organization\WebpSupport;
use PHPUnit\Framework\TestCase;

/**
 * What decodes WebP on this server, as LogoFileValidator asks it (issue #20).
 */
class WebpSupportTest extends TestCase
{
    /**
     * Files written by the running test.
     *
     * @var string[]
     */
    private array $files = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * The answers match what PHP reports about its extensions.
     *
     * @return void
     */
    public function testTheAnswersAreWhatThisPhpCanDo(): void
    {
        $support = new WebpSupport();

        $this->assertSame(
            \function_exists('imagecreatefromwebp') && !empty(gd_info()['WebP Support']),
            $support->gd()
        );
        $this->assertSame(
            class_exists(\Imagick::class) && \Imagick::queryFormats('WEBP') !== [],
            $support->imagick()
        );
    }

    /**
     * @return void
     */
    public function testGdDecodesAWebpAndNotADamagedOne(): void
    {
        $support = new WebpSupport();
        if (!$support->gd()) {
            $this->markTestSkipped('PHP\'s GD was built without WebP.');
        }

        ob_start();
        imagewebp(imagecreatetruecolor(4, 4));
        $webp = (string) ob_get_clean();

        $this->assertTrue($support->decodesWithGd($this->write($webp)));
        $this->assertFalse($support->decodesWithGd($this->write(substr($webp, 0, 20))));
        $this->assertFalse($support->decodesWithGd($this->write('not an image')));
    }

    /**
     * @param string $bytes
     * @return string
     */
    private function write(string $bytes): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'mageos-seo-webp-');
        file_put_contents($file, $bytes);
        $this->files[] = $file;

        return $file;
    }
}
