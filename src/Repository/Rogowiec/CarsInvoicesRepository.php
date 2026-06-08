<?php

namespace App\Repository\Rogowiec;

use App\Repository\IApiRepository;
use Doctrine\DBAL\ParameterType;

class CarsInvoicesRepository extends IApiRepository
{
    private $endpoint = '/wdf/Reports/vehicleInvoiceList?id_oddzial={branch_id}&VIN={vin}';
    protected $table = 'rogowiec_car_invoices';
    private const BATCH_SIZE = 10; // Number of simultaneous requests per batch
    protected $onDuplicateClause = 'ON DUPLICATE KEY UPDATE 
        vehicle_id = VALUES(vehicle_id),
        fv_data = VALUES(fv_data),
        fv_data_wplyw = VALUES(fv_data_wplyw),
        wartosc = VALUES(wartosc),
        faktura_rodzaj = VALUES(faktura_rodzaj),
        kod_typu_korekty = VALUES(kod_typu_korekty),
        typ_korekty = VALUES(typ_korekty),
        fetch_date = NOW()
    ';

    public function fetch(): array
    {
        $this->clearDataArrays();
        // $branches = $this->findBrachesId();
        $vins = $this->findVins();
        $resCount = 0;
        
        // $totalBranches = count($branches);
        $totalVins = count($vins);
        
        // if ($totalBranches === 0) {
        //     throw new \Exception("Brak oddziałów dla " . $this->source->getName() . ". Najpierw uruchom komendę pobierającą listę oddziałów [rogowiec:branch]", 99);
        // }
        
        if ($totalVins === 0) {
            throw new \Exception("Brak VINów dla " . $this->source->getName() . ". Najpierw uruchom komendę pobierającą sprzedane samochody [rogowiec:cars:sold]", 99);
        }
        
        echo "\nPobieram faktury samochodów dla $totalVins VINów w batch'ach po " . self::BATCH_SIZE;
        
        // Create all VIN x Branch combinations
        $allRequests = [];
        foreach ($vins as $vinData) {
            $vin = $vinData['vin'];
            // foreach ($branches as $branch) {
                // $branchId = $branch['branch_id'];
                $branchId = $vinData['branch_id'];
                $allRequests[] = [
                    'vin' => $vin,
                    'branch_id' => $branchId,
                    'url' => str_replace(
                        ['{vin}', '{branch_id}'],
                        [$vin, $branchId],
                        $this->endpoint
                    )
                ];
            // }
        }
        
        $totalRequests = count($allRequests);
        echo "\nŁącznie żądań do wykonania: $totalRequests";
        $totalBatches = (int)ceil($totalRequests / self::BATCH_SIZE);
        
        // Process in batches
        for ($i = 0; $i < $totalRequests; $i += self::BATCH_SIZE) {
            $batchNumber = (int)($i / self::BATCH_SIZE) + 1;
            echo "\nBatch $batchNumber / $totalBatches";
            
            $batchRequests = array_slice($allRequests, $i, self::BATCH_SIZE);
            $urls = [];
            
            foreach ($batchRequests as $index => $request) {
                $urls[$index] = $request['url'];
                echo "\n  VIN: {$request['vin']}, Oddział: {$request['branch_id']} ----> " . ($i + $index + 1) . "/$totalRequests";
            }
            
            $responses = $this->httpClient->requestMulti($this->source, $urls);
            
            foreach ($responses as $idx => $responseRaw) {
                $response = $this->decodeResponseFromRaw($responseRaw);
                if (!empty($response)) {
                    $this->fetchResult = array_merge($this->fetchResult, $response);
                    $resCount += count($response);
                }
            }
            
            // Save when we have enough data
            if (count($this->fetchResult) >= $this->fetchLimit) {
                $this->removeOldRecords($this->fetchResult);
                $this->save();
                $this->clearDataArrays();
            }
        }
        
        // Final save for any remaining results
        if (!empty($this->fetchResult)) {
            $this->removeOldRecords($this->fetchResult);
            $this->save();
            $this->clearDataArrays();
        }
        return ['fetched' => $resCount];
    }

    private function removeOldRecords(array $newData)
    {   
        foreach ($newData as $row) {
            $q = "DELETE FROM {$this->table} WHERE vin = :vin AND fv_numer = :fv_numer AND source = :source";
            $params = [
                'vin' => $row['VIN'],
                'fv_numer' => $row['NumerFaktury'],
                'source' => $this->source->getName()
            ];
            $this->db->executeStatement($q, $params);
        }
        
    }

    private function decodeResponseFromRaw($raw): array
    {
        if (empty($raw) || $raw === false) {
            return [];
        }

        if (str_contains($raw, 'Us³ugaPunktSprzedazy')) {
            $raw = preg_replace('/Us³ugaPunktSprzedazy/', 'UsługaPunktSprzedazy', $raw);
        }
        
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function findBrachesId()
    {
        $q = "SELECT
                source,
                dms_id AS branch_id
            FROM prehurtownia.rogowiec_branch
            WHERE source = :source
            ORDER BY dms_id
        ";
        return $this->db->fetchAllAssociative($q, ['source' => $this->source->getName()], ['source' => ParameterType::STRING]);
    }

    private function findVins()
    {
        $q = "SELECT DISTINCT
                s.source,
                s.vin,
                rb.dms_id AS branch_id
            FROM prehurtownia.rogowiec_cars_sold s
            JOIN prehurtownia.rogowiec_branch rb ON rb.source = s.source AND rb.oddzial_id = CONCAT('0', SUBSTRING(s.fv_numer, 1, 1))
            LEFT JOIN prehurtownia.rogowiec_car_invoices rci ON rci.source = s.source AND rci.vin = s.vin
            WHERE s.source = :source
                AND COALESCE(rci.fetch_date, '1970-01-01') <= DATE_SUB(NOW(), INTERVAL 3 DAY)
                AND s.korekty_wartosc != 0
            ORDER BY vin
                
        ";
        return $this->db->fetchAllAssociative($q, ['source' => $this->source->getName()], ['source' => ParameterType::STRING]);
    }

    protected function getFieldsParams(): array
    {
        return [
            'vehicle_id' => ['sourceField' => 'VehicleId', 'type' => ParameterType::STRING],
            'vin' => ['sourceField' => 'VIN', 'type' => ParameterType::STRING],
            'fv_numer' => ['sourceField' => 'NumerFaktury', 'type' => ParameterType::STRING],
            'fv_data' => ['sourceField' => 'DataWystawieniaFaktury', 'type' => ParameterType::STRING, 'format' => ['date' => 'Y-m-d']],
            'fv_data_wplyw' => ['sourceField' => 'DataWplywuFaktury', 'type' => ParameterType::STRING, 'format' => ['date' => 'Y-m-d']],
            'wartosc' => ['sourceField' => 'WartoscFaktury', 'type' => ParameterType::STRING],
            'faktura_rodzaj' => ['sourceField' => 'RodzajFaktury', 'type' => ParameterType::STRING],
            'kod_typu_korekty' => ['sourceField' => 'KodTypuKorekty', 'type' => ParameterType::STRING],
            'typ_korekty' => ['sourceField' => 'TypKorekty', 'type' => ParameterType::STRING],
            'source' => ['sourceField' => 'source', 'type' => ParameterType::STRING],
        ];
    }
    // Fetch VINs for a given branch
    private function fetchVinsForBranch($branchId)
    {
        $q = "SELECT vin FROM prehurtownia.rogowiec_cars_sold WHERE source = :source AND SUBSTRING(fv_numer, 1, 1) = CAST((SELECT oddzial_id FROM prehurtownia.rogowiec_branch WHERE dms_id = :branchId AND source = :source LIMIT 1) AS UNSIGNED)";
        $result = $this->db->fetchAllAssociative($q, [
            'source' => $this->source->getName(),
            'branchId' => $branchId
        ], [
            'source' => ParameterType::STRING,
            'branchId' => ParameterType::STRING
        ]);
        return array_column($result, 'vin');
    }
}
