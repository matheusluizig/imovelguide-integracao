<?php

namespace App\Integracao\Infrastructure\Helpers;

use Illuminate\Support\Facades\Storage;

class IntegrationImageHelper
{
    /**
     * Obtém a URL completa de uma imagem de integração armazenada no S3
     *
     * @param string $imageName Nome da imagem (com ou sem caminho)
     * @param string $size Tamanho da imagem (small, medium, large, original)
     * @return string URL completa da imagem ou URL de imagem padrão se não existir
     */
    public static function getImageUrl($imageName, $size = 'original')
    {
        try {
            if (empty($imageName)) {
                return self::getDefaultImageUrl();
            }

            $disk = Storage::disk('do_spaces');

            // Para imagens de integração
            if (strpos($imageName, 'integration/') === 0) {
                $cleanName = str_replace('integration/', '', $imageName);

                // Remove extensão .webp se já existir para evitar duplicação
                $cleanName = preg_replace('/\.webp$/', '', $cleanName);

                switch ($size) {
                    case 'small':
                        // Tenta WebP primeiro, depois versão sem extensão
                        $pathWebp = "images/integration/properties/small/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/small/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp; // Padrão para WebP
                        }
                        break;
                    case 'medium':
                        // Tenta WebP primeiro, depois versão sem extensão
                        $pathWebp = "images/integration/properties/medium/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/medium/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp; // Padrão para WebP
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

                // Fallback para versão original se a redimensionada não existir
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

    /**
     * Verifica se uma imagem de integração existe no S3
     *
     * @param string $imageName Nome da imagem
     * @param string $size Tamanho da imagem (opcional)
     * @return bool
     */
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
                        // Tenta WebP primeiro, depois versão sem extensão
                        $pathWebp = "images/integration/properties/small/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/small/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp; // Padrão para WebP
                        }
                        break;
                    case 'medium':
                        // Tenta WebP primeiro, depois versão sem extensão
                        $pathWebp = "images/integration/properties/medium/{$cleanName}.webp";
                        $pathOld = "images/integration/properties/medium/{$cleanName}";
                        if ($disk->exists($pathWebp)) {
                            $path = $pathWebp;
                        } elseif ($disk->exists($pathOld)) {
                            $path = $pathOld;
                        } else {
                            $path = $pathWebp; // Padrão para WebP
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

    /**
     * Obtém a URL da imagem padrão
     *
     * @return string
     */
    private static function getDefaultImageUrl()
    {
        // Você pode configurar uma imagem padrão no S3 ou usar uma URL local
        return asset('images/default-integration-image.jpg');
    }

    /**
     * Obtém URLs de todas as versões de uma imagem de integração
     *
     * @param string $imageName Nome da imagem
     * @return array Array com URLs das diferentes versões
     */
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

    /**
     * Gera o caminho S3 para uma imagem de integração
     *
     * @param string $imageName Nome da imagem
     * @param string $size Tamanho da imagem
     * @return string Caminho completo no S3
     */
    public static function getS3Path($imageName, $size = 'original')
    {
        if (strpos($imageName, 'integration/') === 0) {
            $cleanName = str_replace('integration/', '', $imageName);

            // Remove extensão .webp se já existir para evitar duplicação
            $cleanName = preg_replace('/\.webp$/', '', $cleanName);

            switch ($size) {
                case 'small':
                    // Padrão para WebP, mas mantém compatibilidade
                    return "images/integration/properties/small/{$cleanName}.webp";
                case 'medium':
                    // Padrão para WebP, mas mantém compatibilidade
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
