<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Organization;

use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Image\Adapter\AdapterInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Organization\LogoFileValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #20 against the real image adapters: a logo upload must be the JPEG, PNG, GIF or WebP its
 * name says, and must decode.
 *
 * Each case runs with GD2 and with ImageMagick configured as Magento's image adapter. ImageMagick
 * decodes whatever its content says it is, SVG and PDF included, so the type is checked before
 * any adapter is asked. The ImageMagick cases are skipped where the imagick extension is not loaded.
 *
 * @magentoAppIsolation enabled
 */
class LogoFileValidatorTest extends TestCase
{
    /**
     * Files written by the running test.
     *
     * @var string[]|null
     */
    private ?array $files = [];

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
        $this->files = [];
    }

    /**
     * @dataProvider adapters
     * @param string $adapter
     * @return void
     */
    #[DataProvider('adapters')]
    public function testImagesOfTheTypeTheirNameSaysPass(string $adapter): void
    {
        $validator = $this->validator($adapter);

        $types = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
        foreach ($types as $extension => $type) {
            $validator->validate($this->image($type), $extension);
        }
        $this->addToAssertionCount(5);
    }

    /**
     * @dataProvider refusedFiles
     * @param string $adapter
     * @param string $content Which file: see file()
     * @param string $extension The extension it is uploaded with
     * @param string $message
     * @return void
     */
    #[DataProvider('refusedFiles')]
    public function testAFileThatIsNotWhatItsNameSaysIsRefused(
        string $adapter,
        string $content,
        string $extension,
        string $message
    ): void {
        $validator = $this->validator($adapter);
        $file      = $this->file($content);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);

        $validator->validate($file, $extension);
    }

    /**
     * A file whose header says what it is but whose image data is cut short.
     *
     * Decoders read a JPEG cut short, and GD a GIF, so this is the completeness check's to find.
     *
     * @dataProvider adapters
     * @param string $adapter
     * @return void
     */
    #[DataProvider('adapters')]
    public function testADamagedImageIsRefused(string $adapter): void
    {
        $validator = $this->validator($adapter);

        foreach (['png' => 'png', 'jpg' => 'jpeg', 'gif' => 'gif', 'webp' => 'webp'] as $extension => $type) {
            $whole     = (string) file_get_contents($this->image($type, 64));
            $truncated = $this->write(substr($whole, 0, (int) (\strlen($whole) * 0.6)));
            $this->assertNotFalse(getimagesize($truncated), 'The header still reads as an image.');

            try {
                $validator->validate($truncated, $extension);
                $this->fail(\sprintf('A truncated %s passed with %s.', $type, $adapter));
            } catch (LocalizedException $e) {
                $this->assertSame('The uploaded file is a damaged or incomplete image.', $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adapters(): array
    {
        return [
            'GD2'         => [AdapterInterface::ADAPTER_GD2],
            'ImageMagick' => [AdapterInterface::ADAPTER_IM],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function refusedFiles(): array
    {
        $cases = [];
        foreach (self::adapters() as $name => [$adapter]) {
            $cases += [
                "$name: a Flash movie named .jpg" => [$adapter, 'swf', 'jpg', 'The uploaded file is not a JPEG image.'],
                "$name: a PNG named .jpg"         => [$adapter, 'png', 'jpg', 'The uploaded file is not a JPEG image.'],
                "$name: an SVG named .png"        => [$adapter, 'svg', 'png', 'The uploaded file is not a PNG image.'],
                "$name: a PDF named .gif"         => [$adapter, 'pdf', 'gif', 'The uploaded file is not a GIF image.'],
                "$name: a WebP named .png"        => [$adapter, 'webp', 'png', 'The uploaded file is not a PNG image.'],
                "$name: an SVG named .svg"        => [$adapter, 'svg', 'svg', 'Upload a JPEG, PNG, GIF or WebP image.'],
            ];
        }

        return $cases;
    }

    /**
     * The validator, with the given adapter configured as Magento's.
     *
     * @param string $adapter
     * @return LogoFileValidator
     */
    private function validator(string $adapter): LogoFileValidator
    {
        if ($adapter === AdapterInterface::ADAPTER_IM && !\extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is not loaded, so ImageMagick cannot be configured.');
        }
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue('dev/image/default_adapter', $adapter);

        return Bootstrap::getObjectManager()->create(LogoFileValidator::class);
    }

    /**
     * A file with the given content: an image, a Flash movie header, an SVG or a PDF.
     *
     * @param string $content
     * @return string
     */
    private function file(string $content): string
    {
        return match ($content) {
            'swf'   => $this->swf(),
            'svg'   => $this->write('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'),
            'pdf'   => $this->write(
                "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
            ),
            default => $this->image($content),
        };
    }

    /**
     * An uncompressed Flash movie header of 550 × 400, which getimagesize() reads as SWF.
     *
     * PHP reads 32 bytes after the 8-byte header, so the movie is padded to that.
     *
     * @return string
     */
    private function swf(): string
    {
        $body = str_pad("\x78\x00\x05\x5F\x00\x00\x0F\xA0\x00" . "\x00\x18\x01\x00", 32, "\x00");
        $file = $this->write('FWS' . \chr(10) . pack('V', 8 + \strlen($body)) . $body);

        $info = getimagesize($file);
        $this->assertSame(IMAGETYPE_SWF, $info[2] ?? null, 'The fixture must read as a Flash movie.');

        return $file;
    }

    /**
     * A real image of the given type, drawn with GD, with noise so a larger one has data to cut.
     *
     * @param string $type jpeg, png, gif or webp
     * @param int $size
     * @return string
     */
    private function image(string $type, int $size = 8): string
    {
        $image = imagecreatetruecolor($size, $size);
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }

        $file = $this->write('');
        match ($type) {
            'jpeg' => imagejpeg($image, $file),
            'png'  => imagepng($image, $file),
            'gif'  => imagegif($image, $file),
            'webp' => imagewebp($image, $file),
        };

        return $file;
    }

    /**
     * A temporary file with the given bytes.
     *
     * @param string $bytes
     * @return string
     */
    private function write(string $bytes): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'mageos-seo-logo-');
        file_put_contents($file, $bytes);
        $this->files[] = $file;

        return $file;
    }
}
