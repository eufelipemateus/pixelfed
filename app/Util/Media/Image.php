<?php

namespace App\Util\Media;

use App\Media;
use Intervention\Image\ImageManager;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Cache, Log, Storage;
use App\Util\Media\Blurhash;

class Image
{
    public $square;
    public $landscape;
    public $portrait;
    public $thumbnail;
    public $orientation;
    public $acceptedMimes = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/webp',
        'image/avif',
        'image/heic',
    ];

    protected $imageManager;

    public function __construct()
    {
        ini_set('memory_limit', config('pixelfed.memory_limit', '1024M'));

        $this->square = $this->orientations()['square'];
        $this->landscape = $this->orientations()['landscape'];
        $this->portrait = $this->orientations()['portrait'];
        $this->thumbnail = [
            'width'  => 640,
            'height' => 640,
        ];
        $this->orientation = null;

        $driver = match(config('image.driver')) {
            'imagick' => new \Intervention\Image\Drivers\Imagick\Driver::class,
            'vips' => new \Intervention\Image\Drivers\Vips\Driver::class,
            default => new \Intervention\Image\Drivers\Gd\Driver::class
        };

        $this->imageManager = new ImageManager(
            $driver,
            autoOrientation: true,
            decodeAnimation: true,
            blendingColor: 'ffffff',
            strip: true
        );
    }

    public function orientations()
    {
        return [
            'square' => [
                'width'  => 1080,
                'height' => 1080,
            ],
            'landscape' => [
                'width'  => 1920,
                'height' => 1080,
            ],
            'portrait' => [
                'width'  => 1080,
                'height' => 1350,
            ],
        ];
    }

    public function getAspectRatio($mediaPath, $thumbnail = false)
    {
        if ($thumbnail) {
            return [
                'dimensions'  => $this->thumbnail,
                'orientation' => 'thumbnail',
            ];
        }

        if (!is_file($mediaPath)) {
            throw new \Exception('Invalid Media Path');
        }

        list($width, $height) = getimagesize($mediaPath);
        $aspect = $width / $height;
        $orientation = $aspect === 1 ? 'square' :
        ($aspect > 1 ? 'landscape' : 'portrait');
        $this->orientation = $orientation;

        return [
            'dimensions'  => $this->orientations()[$orientation],
            'orientation' => $orientation,
            'width_original' => $width,
            'height_original' => $height,
        ];
    }

    public function resizeImage(Media $media)
    {
        $this->handleResizeImage($media);
    }

    public function resizeThumbnail(Media $media)
    {
        $this->handleThumbnailImage($media);
    }

    public function handleResizeImage(Media $media)
    {
        $this->handleImageTransform($media, false);
    }

    public function handleThumbnailImage(Media $media)
    {
        $this->handleImageTransform($media, true);
    }

    public function handleImageTransform(Media $media, $thumbnail = false)
    {
        $path = $media->media_path;
        $file = storage_path('app/'.$path);
        if (!in_array($media->mime, $this->acceptedMimes)) {
            return;
        }
        $ratio = $this->getAspectRatio($file, $thumbnail);
        $aspect = $ratio['dimensions'];
        $orientation = $ratio['orientation'];

        try {
            $fileInfo = pathinfo($file);
            $extension = strtolower($fileInfo['extension'] ?? 'jpg');

            $metadata = null;
            if (!$thumbnail && config('media.exif.database', false) == true) {
                try {
                    $exif = @exif_read_data($file);
                    if ($exif) {
                        $meta = [];
                        $keys = [
                            "FileName",
                            "FileSize",
                            "FileType",
                            "Make",
                            "Model",
                            "MimeType",
                            "ColorSpace",
                            "ExifVersion",
                            "Orientation",
                            "UserComment",
                            "XResolution",
                            "YResolution",
                            "FileDateTime",
                            "SectionsFound",
                            "ExifImageWidth",
                            "ResolutionUnit",
                            "ExifImageLength",
                            "FlashPixVersion",
                            "Exif_IFD_Pointer",
                            "YCbCrPositioning",
                            "ComponentsConfiguration",
                            "ExposureTime",
                            "FNumber",
                            "ISOSpeedRatings",
                            "ShutterSpeedValue"
                        ];
                        foreach ($exif as $k => $v) {
                            if (in_array($k, $keys)) {
                                $meta[$k] = $v;
                            }
                        }
                        $media->metadata = json_encode($meta);
                    }
                } catch (\Exception $e) {
                    Log::info('EXIF extraction failed: ' . $e->getMessage());
                }
            }

            $img = $this->imageManager->read($file);

            if ($thumbnail) {
                $img = $img->coverDown(
                    $aspect['width'],
                    $aspect['height']
                );
            } else {
                if (
                    ($ratio['width_original'] > $aspect['width'])
                    || ($ratio['height_original'] > $aspect['height'])
                ) {
                    $img = $img->scaleDown(
                        $aspect['width'],
                        $aspect['height']
                    );
                }
            }

            $converted = $this->setBaseName($path, $thumbnail, $extension);
            $newPath = storage_path('app/'.$converted['path']);

            $quality = config_cache('pixelfed.image_quality');

            $encoder = null;
            switch ($extension) {
                case 'jpeg':
                case 'jpg':
                    $encoder = new JpegEncoder($quality);
                    break;
                case 'png':
                    $encoder = new PngEncoder();
                    break;
                case 'webp':
                    $encoder = new WebpEncoder($quality);
                    break;
                case 'avif':
                    $encoder = new AvifEncoder($quality);
                    break;
                case 'heic':
                    $encoder = new JpegEncoder($quality);
                    $extension = 'jpg';
                    break;
                default:
                    $encoder = new JpegEncoder($quality);
                    $extension = 'jpg';
            }

            $encoded = $encoder->encode($img);

            file_put_contents($newPath, $encoded->toString());

            if ($thumbnail == true) {
                $media->thumbnail_path = $converted['path'];
                $media->thumbnail_url = url(Storage::url($converted['path']));
            } else {
                $media->width = $img->width();
                $media->height = $img->height();
                $media->orientation = $orientation;
                $media->media_path = $converted['path'];
                $media->mime = 'image/' . $extension;
            }

            $media->save();

            if ($thumbnail) {
                $this->generateBlurhash($media);
            }

            Cache::forget('status:transformer:media:attachments:'.$media->status_id);
            Cache::forget('status:thumb:'.$media->status_id);

        } catch (\Exception $e) {
            $media->processed_at = now();
            $media->save();
            Log::info('MediaResizeException: ' . $e->getMessage() . ' | Could not process media id: ' . $media->id);
        }
    }

    public function setBaseName($basePath, $thumbnail, $extension)
    {
        $png = false;
        $path = explode('.', $basePath);
        $name = ($thumbnail == true) ? $path[0].'_thumb' : $path[0];
        $ext = last($path);
        $basePath = "{$name}.{$ext}";

        return ['path' => $basePath, 'png' => $png];
    }

    protected function generateBlurhash($media)
    {
        $blurhash = Blurhash::generate($media);
        if ($blurhash) {
            $media->blurhash = $blurhash;
            $media->save();
        }
    }
}
