<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\AnuncioImages;
use Carbon\Carbon;
use Image;
use Exception;

class ProcessImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600; // 10 minutos para processamento de imagens
    public $retryAfter = 60;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    private int $anuncioId;
    private string $imageUrl;

    public function __construct(int $anuncioId, string $imageUrl)
    {
        $this->anuncioId = $anuncioId;
        $this->imageUrl = $imageUrl;
        $this->onQueue('image-processing');
    }

    public function handle(): void
    {
        try {
            $imageFileName = $this->generateImageFileName();
            $s3Path = "images/{$imageFileName}";

            if (Storage::disk('do_spaces')->exists($s3Path)) {
                $this->createImageRecord($imageFileName);
                return;
            }

            $fileData = $this->downloadImage();
            if (!$fileData) {
                throw new Exception("Falha ao baixar imagem: {$this->imageUrl}");
            }

            $this->processAndUploadImage($fileData, $s3Path, $imageFileName);
            $this->createImageRecord($imageFileName);

        } catch (Exception $e) {
            Log::error("Erro ao processar imagem", [
                'anuncio_id' => $this->anuncioId,
                'image_url' => $this->imageUrl,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function generateImageFileName(): string
    {
        return 'integration/' . md5($this->anuncioId . basename($this->imageUrl)) . '.webp';
    }

    private function downloadImage(): ?string
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 OPR/107.0.0.0\r\n" .
                           "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7\r\n",
                'timeout' => 30
            ]
        ]);

        $fileData = @file_get_contents($this->imageUrl, false, $context);
        return $fileData ?: null;
    }

    private function processAndUploadImage(string $fileData, string $s3Path, string $imageFileName): void
    {
        $imageObject = Image::make($fileData);
        $originalData = $imageObject->encode('webp', 85)->getEncoded();

        $this->storePublicObject($s3Path, $originalData);

        $baseDir = $this->ensureLocalDirectory();
        $basePath = $baseDir . DIRECTORY_SEPARATOR . $imageFileName;
        $imageObject->save($basePath);

        $this->createImageVariants($s3Path, $imageFileName);
    }

    private function createImageVariants(string $s3Path, string $imageFileName): void
    {
        try {
            $imageObject = Image::make(public_path("images/{$imageFileName}"));
            
            $smallPath = str_replace('.webp', '_small.webp', $s3Path);
            $mediumPath = str_replace('.webp', '_medium.webp', $s3Path);

            $smallData = $imageObject->resize(300, 200, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->encode('webp', 80)->getEncoded();

            $mediumData = $imageObject->resize(600, 400, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->encode('webp', 85)->getEncoded();

            $this->storePublicObject($smallPath, $smallData);
            $this->storePublicObject($mediumPath, $mediumData);

        } catch (Exception $e) {
            Log::warning("Erro ao criar variantes da imagem", [
                'image_file' => $imageFileName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function ensureLocalDirectory(): string
    {
        $baseDir = public_path('images');
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
                throw new Exception('Falha ao criar diretório local de imagens');
            }
        }

        return $baseDir;
    }

    private function storePublicObject(string $path, string $contents): void
    {
        $stored = Storage::disk('do_spaces')->put($path, $contents, 'public');
        if (!$stored) {
            throw new Exception("Falha ao armazenar arquivo em {$path}");
        }
    }

    private function createImageRecord(string $imageFileName): void
    {
        AnuncioImages::create([
            'anuncio_id' => $this->anuncioId,
            'name' => $imageFileName,
            'created_at' => Carbon::now()->toDateTimeString()
        ]);
    }

    public function failed(Exception $exception): void
    {
        Log::error("Job de processamento de imagem falhou definitivamente", [
            'anuncio_id' => $this->anuncioId,
            'image_url' => $this->imageUrl,
            'error' => $exception->getMessage()
        ]);
    }
}