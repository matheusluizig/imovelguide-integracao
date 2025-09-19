<?php

namespace App\Integracao\Application\Services;

use App\Anuncio;
use App\AnuncioEndereco;
use App\AnuncioBeneficio;
use App\AnuncioImages;
use App\CondominiumData;
use App\Imovel;
use App\Bairro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class BulkIntegrationService
{
    private array $anunciosToInsert = [];
    private array $anunciosToUpdate = [];
    private array $enderecosToInsert = [];
    private array $enderecosToUpdate = [];
    private array $beneficiosToInsert = [];
    private array $condominiumDataToInsert = [];
    private array $condominiumDataToUpdate = [];
    private array $imagesToInsert = [];

    private Collection $existingAnuncios;
    private Collection $existingEnderecos;
    private Collection $existingBeneficios;
    private Collection $existingCondominiumData;
    private Collection $condominiums;
    private Collection $districts;

    public function __construct(int $userId, Collection $xmlData)
    {
        $this->loadExistingData($userId);
        $this->loadAuxiliaryData($xmlData);
    }

    private function loadExistingData(int $userId): void
    {
        $this->existingAnuncios = Anuncio::select('id', 'codigo', 'user_id')
            ->where('user_id', $userId)
            ->where('xml', 1)
            ->get()
            ->keyBy('codigo');

        $anuncioIds = $this->existingAnuncios->pluck('id')->toArray();

        $this->existingEnderecos = AnuncioEndereco::whereIn('anuncio_id', $anuncioIds)
            ->get()
            ->keyBy('anuncio_id');

        $this->existingBeneficios = AnuncioBeneficio::whereIn('anuncio_id', $anuncioIds)
            ->get()
            ->groupBy('anuncio_id');

        $this->existingCondominiumData = CondominiumData::whereIn('ad_id', $anuncioIds)
            ->get()
            ->keyBy('ad_id');
    }

    private function loadAuxiliaryData(Collection $xmlData): void
    {
        $ceps = $xmlData->pluck('CEP')->unique()->filter()->toArray();
        $cities = $xmlData->pluck('Cidade')->unique()->map(fn($city) => \Str::slug($city))->toArray();
        $districts = $xmlData->pluck('Bairro')->unique()->map(fn($district) => \Str::slug($district))->toArray();

        $this->condominiums = Imovel::select('id', 'cep', 'endereco')
            ->whereIn('cep', $ceps)
            ->get()
            ->groupBy('cep');

        $this->districts = Bairro::select('id', 'slug', 'slug_cidade', 'uf_true as uf')
            ->whereIn('slug_cidade', $cities)
            ->whereIn('slug', $districts)
            ->get()
            ->groupBy(['uf', 'slug_cidade', 'slug']);
    }

    public function addAnuncio(array $imovelData, int $userId): int
    {
        $codigo = $imovelData['CodigoImovel'];
        $existingAnuncio = $this->existingAnuncios->get($codigo);

        $anuncioData = $this->prepareAnuncioData($imovelData, $userId);

        if ($existingAnuncio) {
            if ($this->isAnuncioDifferent($existingAnuncio, $anuncioData)) {
                $anuncioData['id'] = $existingAnuncio->id;
                $anuncioData['updated_at'] = Carbon::now('America/Sao_Paulo');
                $this->anunciosToUpdate[] = $anuncioData;
            }
            return $existingAnuncio->id;
        } else {
            $anuncioData['created_at'] = Carbon::now('America/Sao_Paulo');
            $anuncioData['updated_at'] = Carbon::now('America/Sao_Paulo');
            $this->anunciosToInsert[] = $anuncioData;
            return 0; // Será atualizado após insert
        }
    }

    public function addEndereco(int $anuncioId, array $imovelData): void
    {
        $enderecoData = $this->prepareEnderecoData($anuncioId, $imovelData);
        $existingEndereco = $this->existingEnderecos->get($anuncioId);

        if ($existingEndereco) {
            if ($this->isEnderecoDifferent($existingEndereco, $enderecoData)) {
                $enderecoData['anuncio_id'] = $anuncioId;
                $this->enderecosToUpdate[] = $enderecoData;
            }
        } else {
            $this->enderecosToInsert[] = $enderecoData;
        }
    }

    public function addBeneficios(int $anuncioId, array $features): void
    {
        if (empty($features)) {
            return;
        }

        $existingBeneficios = $this->existingBeneficios->get($anuncioId, collect());
        $existingFeatures = $existingBeneficios->pluck('beneficio_id')->toArray();

        if (array_diff($features, $existingFeatures) || array_diff($existingFeatures, $features)) {
            $this->beneficiosToInsert[] = [
                'anuncio_id' => $anuncioId,
                'beneficios' => $features
            ];
        }
    }

    public function addCondominiumData(int $anuncioId, array $imovelData): void
    {
        $condominium = $this->findCondominium($imovelData);
        if (!$condominium) {
            return;
        }

        $condominiumData = $this->prepareCondominiumData($anuncioId, $imovelData, $condominium);
        $existingData = $this->existingCondominiumData->get($anuncioId);

        if ($existingData) {
            if ($this->isCondominiumDataDifferent($existingData, $condominiumData)) {
                $condominiumData['ad_id'] = $anuncioId;
                $this->condominiumDataToUpdate[] = $condominiumData;
            }
        } else {
            $this->condominiumDataToInsert[] = $condominiumData;
        }
    }

    public function addImages(int $anuncioId, array $imageUrls): void
    {
        if (empty($imageUrls)) {
            return;
        }

        foreach (array_slice($imageUrls, 0, 20) as $url) {
            $this->imagesToInsert[] = [
                'anuncio_id' => $anuncioId,
                'url' => $url,
                'created_at' => Carbon::now()->toDateTimeString()
            ];
        }
    }

    public function executeBulkOperations(): array
    {
        $results = [
            'anuncios_inserted' => 0,
            'anuncios_updated' => 0,
            'enderecos_inserted' => 0,
            'enderecos_updated' => 0,
            'beneficios_processed' => 0,
            'condominium_data_inserted' => 0,
            'condominium_data_updated' => 0,
            'images_queued' => 0
        ];

        DB::transaction(function () use (&$results) {
            if (!empty($this->anunciosToInsert)) {
                $insertedIds = Anuncio::insertGetIds($this->anunciosToInsert);
                $results['anuncios_inserted'] = count($insertedIds);
                $this->updateAnuncioIds($insertedIds);
            }

            if (!empty($this->anunciosToUpdate)) {
                $this->bulkUpdateAnuncios();
                $results['anuncios_updated'] = count($this->anunciosToUpdate);
            }

            if (!empty($this->enderecosToInsert)) {
                AnuncioEndereco::insert($this->enderecosToInsert);
                $results['enderecos_inserted'] = count($this->enderecosToInsert);
            }

            if (!empty($this->enderecosToUpdate)) {
                $this->bulkUpdateEnderecos();
                $results['enderecos_updated'] = count($this->enderecosToUpdate);
            }

            if (!empty($this->beneficiosToInsert)) {
                $this->bulkProcessBeneficios();
                $results['beneficios_processed'] = count($this->beneficiosToInsert);
            }

            if (!empty($this->condominiumDataToInsert)) {
                CondominiumData::insert($this->condominiumDataToInsert);
                $results['condominium_data_inserted'] = count($this->condominiumDataToInsert);
            }

            if (!empty($this->condominiumDataToUpdate)) {
                $this->bulkUpdateCondominiumData();
                $results['condominium_data_updated'] = count($this->condominiumDataToUpdate);
            }

            if (!empty($this->imagesToInsert)) {
                $this->queueImageProcessing();
                $results['images_queued'] = count($this->imagesToInsert);
            }
        });

        return $results;
    }

    private function prepareAnuncioData(array $imovelData, int $userId): array
    {
        return [
            'user_id' => $userId,
            'status' => 'ativado',
            'type_id' => $imovelData['TipoImovel'],
            'condominio_id' => $this->findCondominiumId($imovelData),
            'new_immobile' => $imovelData['Novo'],
            'negotiation_id' => $imovelData['NegotiationId'],
            'condominio_mes' => $imovelData['PrecoCondominio'],
            'valor' => $imovelData['PrecoVenda'],
            'valor_aluguel' => $imovelData['PrecoLocacao'],
            'valor_temporada' => $imovelData['PrecoTemporada'],
            'rental_guarantee' => $imovelData['GarantiaAluguel'],
            'area_total' => $imovelData['AreaTotal'],
            'area_util' => $imovelData['AreaUtil'],
            'area_terreno' => $imovelData['AreaTerreno'],
            'area_construida' => $imovelData['AreaConstruida'],
            'bedrooms' => $imovelData['QtdDormitorios'],
            'suites' => $imovelData['QtdSuites'],
            'bathrooms' => $imovelData['QtdBanheiros'],
            'codigo' => $imovelData['CodigoImovel'],
            'parking' => $imovelData['QtdVagas'],
            'description' => $imovelData['Descricao'],
            'slug' => $imovelData['ImovelSlug'],
            'title' => $imovelData['ImovelTitle'],
            'usage_type_id' => 1,
            'iptu' => $imovelData['ValorIPTU'],
            'xml' => 1,
            'spotlight' => $imovelData['Spotlight'],
            'subtitle' => $imovelData['Subtitle'],
            'exchange' => $imovelData['Permuta'],
            'youtube' => $imovelData['Video']
        ];
    }

    private function prepareEnderecoData(int $anuncioId, array $imovelData): array
    {
        $endereco = [
            'anuncio_id' => $anuncioId,
            'mostrar_endereco' => $imovelData['MostrarEndereco'],
            'cep' => $imovelData['CEP'],
            'cidade' => ucwords(mb_strtolower($imovelData['Cidade'])),
            'slug_cidade' => $imovelData['CidadeSlug'],
            'uf' => $imovelData['UF'],
            'bairro' => ucwords(mb_strtolower($imovelData['Bairro'])),
            'slug_bairro' => $imovelData['BairroSlug'],
            'logradouro' => ucwords(mb_strtolower($imovelData['Endereco'])),
            'numero' => $imovelData['Numero'],
            'bairro_comercial' => ucwords(mb_strtolower($imovelData['BairroComercial'] ?? '')),
            'latitude' => $imovelData['Latitude'],
            'longitude' => $imovelData['Longitude'],
            'created_at' => Carbon::now()->toDateTimeString()
        ];

        $validLocation = $this->findValidLocation($imovelData);
        $endereco['valid_location'] = $validLocation ? $validLocation->id : 0;

        return $endereco;
    }

    private function prepareCondominiumData(int $anuncioId, array $imovelData, $condominium): array
    {
        $builder = null;
        if ($imovelData['Construtora']) {
            $builder = DB::table('builders')->where('name', $imovelData['Construtora'])->value('id');
        }

        return [
            'condominiun_id' => $condominium->id,
            'ad_id' => $anuncioId,
            'builder_id' => $builder,
            'number_of_floors' => $imovelData['Andares'],
            'units_per_floor' => $imovelData['UnidadesAndar'],
            'number_of_towers' => $imovelData['Torres'],
            'construction_year' => $imovelData['AnoConstrucao'],
            'terrain_size' => $imovelData['AreaTerreno']
        ];
    }

    private function findCondominium(array $imovelData)
    {
        $cep = $imovelData['CEP'];
        $location = ucwords(mb_strtolower($imovelData['Endereco'])) . ", " . $imovelData['Numero'];

        $condominiums = $this->condominiums->get($cep, collect());
        return $condominiums->first(function ($item) use ($location) {
            return false !== stristr($item->endereco, $location);
        });
    }

    private function findCondominiumId(array $imovelData): int
    {
        $condominium = $this->findCondominium($imovelData);
        return $condominium ? $condominium->id : 0;
    }

    private function findValidLocation(array $imovelData)
    {
        $uf = $imovelData['UF'];
        $citySlug = $imovelData['CidadeSlug'];
        $districtSlug = $imovelData['BairroSlug'];

        return $this->districts
            ->get($uf, collect())
            ->get($citySlug, collect())
            ->get($districtSlug);
    }

    private function isAnuncioDifferent($existing, array $new): bool
    {
        $fields = ['valor', 'valor_aluguel', 'area_total', 'bedrooms', 'description', 'title'];
        foreach ($fields as $field) {
            if (($existing->$field ?? null) !== ($new[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function isEnderecoDifferent($existing, array $new): bool
    {
        $fields = ['cep', 'cidade', 'bairro', 'logradouro', 'numero'];
        foreach ($fields as $field) {
            if (($existing->$field ?? null) !== ($new[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function isCondominiumDataDifferent($existing, array $new): bool
    {
        $fields = ['number_of_floors', 'units_per_floor', 'number_of_towers', 'construction_year'];
        foreach ($fields as $field) {
            if (($existing->$field ?? null) !== ($new[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function updateAnuncioIds(array $insertedIds): void
    {
        $index = 0;
        foreach ($this->anunciosToInsert as &$anuncio) {
            $anuncio['id'] = $insertedIds[$index++];
        }
    }

    private function bulkUpdateAnuncios(): void
    {
        foreach ($this->anunciosToUpdate as $anuncio) {
            Anuncio::where('id', $anuncio['id'])->update($anuncio);
        }
    }

    private function bulkUpdateEnderecos(): void
    {
        foreach ($this->enderecosToUpdate as $endereco) {
            AnuncioEndereco::where('anuncio_id', $endereco['anuncio_id'])->update($endereco);
        }
    }

    private function bulkUpdateCondominiumData(): void
    {
        foreach ($this->condominiumDataToUpdate as $data) {
            CondominiumData::where('ad_id', $data['ad_id'])->update($data);
        }
    }

    private function bulkProcessBeneficios(): void
    {
        foreach ($this->beneficiosToInsert as $beneficioData) {
            AnuncioBeneficio::where('anuncio_id', $beneficioData['anuncio_id'])->delete();

            $beneficios = [];
            foreach ($beneficioData['beneficios'] as $beneficioId) {
                $beneficios[] = [
                    'anuncio_id' => $beneficioData['anuncio_id'],
                    'beneficio_id' => $beneficioId,
                    'created_at' => Carbon::now()->toDateTimeString()
                ];
            }

            if (!empty($beneficios)) {
                AnuncioBeneficio::insert($beneficios);
            }
        }
    }

    private function queueImageProcessing(): void
    {
        foreach ($this->imagesToInsert as $imageData) {
            \App\Jobs\ProcessImageJob::dispatch($imageData['anuncio_id'], $imageData['url'])
                ->onQueue('image-processing');
        }
    }
}
