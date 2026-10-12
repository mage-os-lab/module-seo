<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Organization;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Image\Adapter\AdapterInterface;
use Magento\Framework\Image\Adapter\ConfigInterface as ImageAdapterConfig;
use Magento\Framework\Image\AdapterFactory;
use MageOS\Seo\Model\Organization\ImageCompleteness;
use MageOS\Seo\Model\Organization\LogoFileValidator;
use MageOS\Seo\Model\Organization\WebpSupport;
use PHPUnit\Framework\TestCase;

/**
 * Issue #20: which decoder checks a logo for damage, with either of Magento's image adapters
 * configured. Test/Integration/Model/Organization/LogoFileValidatorTest decodes real files with both.
 */
class LogoFileValidatorTest extends TestCase
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
     * JPEG, PNG and GIF are decoded by the configured adapter, whichever it is; WebP support is not
     * asked about.
     *
     * @return void
     */
    public function testJpegPngAndGifAreDecodedByTheConfiguredAdapter(): void
    {
        foreach ([AdapterInterface::ADAPTER_GD2, AdapterInterface::ADAPTER_IM] as $alias) {
            foreach (['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif'] as $extension => $type) {
                $file    = $this->image($type);
                $adapter = $this->createMock(AdapterInterface::class);
                $adapter->expects($this->once())->method('open')->with($file);
                $webp = $this->createMock(WebpSupport::class);
                $webp->expects($this->never())->method($this->anything());

                $this->validator($alias, $this->factoryFor($adapter), $webp)->validate($file, $extension);
            }
        }
    }

    /**
     * Magento's GD2 adapter cannot open WebP: GD itself decodes it.
     *
     * @return void
     */
    public function testWithGd2AWebpIsDecodedByGd(): void
    {
        $file    = $this->image('webp');
        $factory = $this->createMock(AdapterFactory::class);
        $factory->expects($this->never())->method('create');
        $webp = $this->webpMock(imagick: true, gd: true);
        $webp->expects($this->once())->method('decodesWithGd')->with($file)->willReturn(true);

        $this->validator(AdapterInterface::ADAPTER_GD2, $factory, $webp)->validate($file, 'webp');
    }

    /**
     * @return void
     */
    public function testWithImageMagickReadingWebpTheAdapterDecodesIt(): void
    {
        $file    = $this->image('webp');
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())->method('open')->with($file);
        $webp = $this->webpMock(imagick: true, gd: true);
        $webp->expects($this->never())->method('decodesWithGd');

        $this->validator(AdapterInterface::ADAPTER_IM, $this->factoryFor($adapter), $webp)->validate($file, 'webp');
    }

    /**
     * @return void
     */
    public function testWithImageMagickNotReadingWebpGdDecodesIt(): void
    {
        $file    = $this->image('webp');
        $factory = $this->createMock(AdapterFactory::class);
        $factory->expects($this->never())->method('create');
        $webp = $this->webpMock(imagick: false, gd: true);
        $webp->expects($this->once())->method('decodesWithGd')->with($file)->willReturn(true);

        $this->validator(AdapterInterface::ADAPTER_IM, $factory, $webp)->validate($file, 'webp');
    }

    /**
     * Where nothing can decode WebP, it is refused, never accepted unchecked.
     *
     * @return void
     */
    public function testAWebpNothingCanDecodeIsRefused(): void
    {
        $webp = $this->webpMock(imagick: false, gd: false);
        $webp->expects($this->never())->method('decodesWithGd');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('WebP images cannot be checked on this server. Upload a JPEG, PNG or GIF image.');

        $this->validator(AdapterInterface::ADAPTER_IM, $this->createStub(AdapterFactory::class), $webp)
            ->validate($this->image('webp'), 'webp');
    }

    /**
     * @return void
     */
    public function testAWebpGdCannotDecodeIsDamaged(): void
    {
        $webp = $this->webp(imagick: false, gd: true);
        $webp->method('decodesWithGd')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The uploaded file is a damaged or incomplete image.');

        $this->validator(AdapterInterface::ADAPTER_GD2, $this->createStub(AdapterFactory::class), $webp)
            ->validate($this->image('webp'), 'webp');
    }

    /**
     * @return void
     */
    public function testAFileTheAdapterCannotOpenIsDamaged(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('open')->willThrowException(new \Exception('gd-png: libpng error'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The uploaded file is a damaged or incomplete image.');

        $this->validator(AdapterInterface::ADAPTER_GD2, $this->factoryFor($adapter), $this->webp(false, false))
            ->validate($this->image('png'), 'PNG');
    }

    /**
     * A file cut short is refused by its structure, before any decoder, which might read it.
     *
     * @return void
     */
    public function testAFileCutShortIsRefusedBeforeAnyDecode(): void
    {
        $whole   = (string) file_get_contents($this->image('jpeg'));
        $factory = $this->createMock(AdapterFactory::class);
        $factory->expects($this->never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The uploaded file is a damaged or incomplete image.');

        $this->validator(AdapterInterface::ADAPTER_IM, $factory, $this->webp(true, true))
            ->validate($this->write(substr($whole, 0, -2)), 'jpg');
    }

    /**
     * An image too large for the memory limit is said to be, not called damaged.
     *
     * @return void
     */
    public function testAnImageTooLargeToOpenIsSaidToBe(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('open')->willThrowException(new \OverflowException('Memory limit has been reached.'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The image is too large to check within the server\'s memory limit.');

        $this->validator(AdapterInterface::ADAPTER_GD2, $this->factoryFor($adapter), $this->webp(false, false))
            ->validate($this->image('jpeg'), 'jpeg');
    }

    /**
     * An adapter that cannot be set up is the server's fault: its error is not passed off as the
     * file's.
     *
     * @return void
     */
    public function testAnAdapterThatCannotBeSetUpIsNotCalledADamagedFile(): void
    {
        $factory = $this->createStub(AdapterFactory::class);
        $factory->method('create')->willThrowException(new \InvalidArgumentException('Image adapter is not selected.'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image adapter is not selected.');

        $this->validator(AdapterInterface::ADAPTER_GD2, $factory, $this->webp(false, false))
            ->validate($this->image('gif'), 'gif');
    }

    /**
     * The type is the bytes', and must be the extension's, before any adapter is asked.
     *
     * @return void
     */
    public function testATypeOtherThanTheExtensionsIsRefusedBeforeAnyDecode(): void
    {
        $factory = $this->createMock(AdapterFactory::class);
        $factory->expects($this->never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The uploaded file is not a GIF image.');

        $this->validator(AdapterInterface::ADAPTER_IM, $factory, $this->webp(true, true))
            ->validate($this->image('png'), 'gif');
    }

    /**
     * @return void
     */
    public function testAnExtensionOutsideTheFourIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Upload a JPEG, PNG, GIF or WebP image.');

        $factory = $this->createStub(AdapterFactory::class);
        $this->validator(AdapterInterface::ADAPTER_GD2, $factory, $this->webp(true, true))
            ->validate($this->image('png'), 'svg');
    }

    /**
     * @return void
     */
    public function testAFileThatIsNoImageAtAllIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The uploaded file is not a JPEG image.');

        $factory = $this->createStub(AdapterFactory::class);
        $this->validator(AdapterInterface::ADAPTER_GD2, $factory, $this->webp(true, true))
            ->validate($this->write('<?php echo "not an image";'), 'jpg');
    }

    /**
     * @param string $alias
     * @param AdapterFactory $factory
     * @param WebpSupport $webp
     * @return LogoFileValidator
     */
    private function validator(string $alias, AdapterFactory $factory, WebpSupport $webp): LogoFileValidator
    {
        $config = $this->createStub(ImageAdapterConfig::class);
        $config->method('getAdapterAlias')->willReturn($alias);

        return new LogoFileValidator($factory, $config, $webp, new ImageCompleteness(new FileDriver()));
    }

    /**
     * A factory that creates the given adapter.
     *
     * @param AdapterInterface $adapter
     * @return AdapterFactory
     */
    private function factoryFor(AdapterInterface $adapter): AdapterFactory
    {
        $factory = $this->createStub(AdapterFactory::class);
        $factory->method('create')->willReturn($adapter);

        return $factory;
    }

    /**
     * WebP support as given.
     *
     * @param bool $imagick
     * @param bool $gd
     * @return WebpSupport&\PHPUnit\Framework\MockObject\Stub
     */
    private function webp(bool $imagick, bool $gd): WebpSupport&\PHPUnit\Framework\MockObject\Stub
    {
        $webp = $this->createStub(WebpSupport::class);
        $webp->method('imagick')->willReturn($imagick);
        $webp->method('gd')->willReturn($gd);

        return $webp;
    }

    /**
     * WebP support as given, for a test that sets expectations on it.
     *
     * @param bool $imagick
     * @param bool $gd
     * @return WebpSupport&\PHPUnit\Framework\MockObject\MockObject
     */
    private function webpMock(bool $imagick, bool $gd): WebpSupport&\PHPUnit\Framework\MockObject\MockObject
    {
        $webp = $this->createMock(WebpSupport::class);
        $webp->method('imagick')->willReturn($imagick);
        $webp->method('gd')->willReturn($gd);

        return $webp;
    }

    /**
     * A real image of the given type, drawn with GD.
     *
     * @param string $type jpeg, png, gif or webp
     * @return string
     */
    private function image(string $type): string
    {
        $image = imagecreatetruecolor(4, 4);
        $file  = $this->write('');
        match ($type) {
            'jpeg'  => imagejpeg($image, $file),
            'png'   => imagepng($image, $file),
            'gif'   => imagegif($image, $file),
            'webp'  => imagewebp($image, $file),
            default => throw new \InvalidArgumentException($type),
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
