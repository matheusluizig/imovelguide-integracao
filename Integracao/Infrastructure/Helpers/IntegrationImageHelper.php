<?php

namespace App\Integracao\Infrastructure\Helpers;

use Illuminate\Support\Facades\Storage;

class IntegrationImageHelper
{







    public static function getImageUrl($imageName, $size = 'original')
    {
        try {
            if (empty($imageName)) {
                return self::getDefaultImageUrl();
            }

            $disk = Storage::disk('do_spaces');

            if (strpos($imageName, 'integration/') === 0) {
                $cleanName = str_replace('integration/', '', $imageName);

                $cleanName = preg_replace('/\.webp$/', '', $cleanName);

                switch ($size) {
                    case 'small':

                        $pathWebp = "images/integration/properties/small/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/small/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp;
                        }
                        break;
                    case 'medium':

                        $pathWebp = "images/integration/properties/medium/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/medium/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp;
                        }
                        break;
                    case 'large':
                    case 'original':
                    default:
                        $path = "images/{$imageName}";
                        break;
                }

                if ($disk->exists($path)) {
                    return $disk->url($path);
                }

                $originalPath = "images/{$imageName}";
                if ($disk->exists($originalPath)) {
                    return $disk->url($originalPath);
                }
            }

            return self::getDefaultImageUrl();

        } catch (\Exception $e) {
            \Log::error('Erro ao obter URL da imagem de integração: ' . $e->getMessage(), [
                'imageName' => $imageName,
                'size' => $size,
            ]);
            return self::getDefaultImageUrl();
        }
    }








    public static function imageExists($imageName, $size = 'original')
    {
        try {
            if (empty($imageName)) {
                return false;
            }

            $disk = Storage::disk('do_spaces');

            if (strpos($imageName, 'integration/') === 0) {
                $cleanName = str_replace('integration/', '', $imageName);

                switch ($size) {
                    case 'small':

                        $pathWebp = "images/integration/properties/small/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/small/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp;
                        }
                        break;
                    case 'medium':

                        $pathWebp = "images/integration/properties/medium/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/medium/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp;
                        }
                        break;
                    case 'large':
                    case 'original':
                    default:
                        $path = "images/{$imageName}";
                        break;
                }

                return $disk->exists($path);
            } else {
                $path = "images/{$imageName}";
                return $disk->exists($path);
            }

        } catch (\Exception $e) {
            \Log::error('Erro ao verificar existência da imagem de integração: ' . $e->getMessage(), [
                'imageName' => $imageName,
                'size' => $size,
            ]);
            return false;
        }
    }






    private static function getDefaultImageUrl()
    {

        return asset('images/default-integration-image.jpg');
    }







    public static function getAllImageVersions($imageName)
    {
        $versions = [
            'original' => self::getImageUrl($imageName, 'original'),
            'large' => self::getImageUrl($imageName, 'large'),
            'medium' => self::getImageUrl($imageName, 'medium'),
            'small' => self::getImageUrl($imageName, 'small'),
        ];

        return $versions;
    }








    public static function getS3Path($imageName, $size = 'original')
    {
        if (strpos($imageName, 'integration/') === 0) {
            $cleanName = str_replace('integration/', '', $imageName);

            $cleanName = preg_replace('/\.webp$/', '', $cleanName);

            switch ($size) {
                case 'small':

                    return "images/integration/properties/small/{$cleanName}.webp";
                case 'medium':

                    return "images/integration/properties/medium/{$cleanName}.webp";
                case 'large':
                case 'original':
                default:
                    return "images/{$imageName}";
            }
        }

        return "images/{$imageName}";
    }
}
