<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Organization;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageOS\Seo\Model\Organization\ImageCompleteness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #20: an image cut short is found by its structure, whatever decodes it.
 */
class ImageCompletenessTest extends TestCase
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
     * A whole image is complete, and the same image cut at any byte is not.
     *
     * @dataProvider types
     * @param string $type
     * @param int $imageType
     * @param bool $interlaced A progressive JPEG, an interlaced PNG or GIF
     * @return void
     */
    #[DataProvider('types')]
    public function testAWholeImageIsCompleteAndEveryCutOneIsNot(string $type, int $imageType, bool $interlaced): void
    {
        $bytes = $this->image($type, $interlaced);
        $this->assertTrue($this->isComplete($bytes, $imageType), 'The whole image.');

        $length = \strlen($bytes);
        for ($cut = 1; $cut < $length; $cut++) {
            $this->assertFalse(
                $this->isComplete(substr($bytes, 0, $cut), $imageType),
                \sprintf('Cut to %d of %d bytes.', $cut, $length)
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    public static function types(): array
    {
        return [
            'JPEG'             => ['jpeg', IMAGETYPE_JPEG, false],
            'progressive JPEG' => ['jpeg', IMAGETYPE_JPEG, true],
            'PNG'              => ['png', IMAGETYPE_PNG, false],
            'interlaced PNG'   => ['png', IMAGETYPE_PNG, true],
            'GIF'              => ['gif', IMAGETYPE_GIF, false],
            'interlaced GIF'   => ['gif', IMAGETYPE_GIF, true],
            'WebP'             => ['webp', IMAGETYPE_WEBP, false],
        ];
    }

    /**
     * An EXIF thumbnail ends with its own end-of-image marker; the walk skips it by its length.
     *
     * @return void
     */
    public function testAThumbnailsEndMarkerDoesNotCompleteAJpeg(): void
    {
        $bytes   = $this->image('jpeg', false);
        $payload = "Exif\x00\x00" . "\xFF\xD8\xFF\xD9";
        $withApp = "\xFF\xD8" . "\xFF\xE1" . pack('n', 2 + \strlen($payload)) . $payload . substr($bytes, 2);

        $this->assertTrue($this->isComplete($withApp, IMAGETYPE_JPEG));
        $this->assertFalse($this->isComplete(substr($withApp, 0, (int) (\strlen($withApp) * 0.8)), IMAGETYPE_JPEG));
    }

    /**
     * A GIF cut short and ending in the trailer's byte by chance is still cut short.
     *
     * @return void
     */
    public function testAStrayTrailerByteDoesNotCompleteAGif(): void
    {
        $bytes = $this->image('gif', false);

        $this->assertFalse($this->isComplete(substr($bytes, 0, (int) (\strlen($bytes) / 2)) . ';', IMAGETYPE_GIF));
    }

    /**
     * Bytes of another format, or none, are not a complete image of the type.
     *
     * @return void
     */
    public function testOtherBytesAreNotComplete(): void
    {
        $png = $this->image('png', false);

        $this->assertFalse($this->isComplete($png, IMAGETYPE_JPEG));
        $this->assertFalse($this->isComplete($png, IMAGETYPE_GIF));
        $this->assertFalse($this->isComplete($png, IMAGETYPE_WEBP));
        $this->assertFalse($this->isComplete('', IMAGETYPE_PNG));
        $this->assertFalse($this->isComplete($png, IMAGETYPE_BMP), 'A type outside the four.');
    }

    /**
     * Whether the bytes, written to a file, are a complete image of the type.
     *
     * @param string $bytes
     * @param int $type
     * @return bool
     */
    private function isComplete(string $bytes, int $type): bool
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'mageos-seo-complete-');
        file_put_contents($file, $bytes);
        $this->files[] = $file;

        return (new ImageCompleteness(new FileDriver()))->isComplete($file, $type);
    }

    /**
     * An image of the given type drawn with GD, with noise so its data spans several blocks.
     *
     * @param string $type
     * @param bool $interlaced
     * @return string
     */
    private function image(string $type, bool $interlaced): string
    {
        $image = imagecreatetruecolor(24, 24);
        for ($x = 0; $x < 24; $x++) {
            for ($y = 0; $y < 24; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        imageinterlace($image, $interlaced);

        ob_start();
        match ($type) {
            'jpeg'  => imagejpeg($image),
            'png'   => imagepng($image),
            'gif'   => imagegif($image),
            'webp'  => imagewebp($image),
            default => throw new \InvalidArgumentException($type),
        };

        return (string) ob_get_clean();
    }
}
