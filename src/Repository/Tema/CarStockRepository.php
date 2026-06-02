<?php

namespace App\Repository\Tema;

use App\Repository\IApiRepository;
use Doctrine\DBAL\ParameterType;

class CarStockRepository extends IApiRepository
{
    private string $endpoint = '/api/dms/v1/stocks/{stockId}/:sync';
    protected $table = 'tema_car_stock';

    public function fetch(): array
    {
        $this->clearDataArrays();
        $stocks = $this->getStocks();
        $stocksCount = count($stocks);
        $resCount = 0;

        if ($stocksCount) {
            $i = 1;

            foreach ($stocks as $stock) {
                echo "\nNr stocku $stock ----> $i/$stocksCount";

                $url = str_replace('{stockId}', $stock, $this->endpoint);
                $res = $this->fetchApiResult($url);

                if (!empty($res)) {
                    foreach ($res['items'] as $r) {
                        array_push($this->fetchResult, [
                            'stock_id' => $stock,
                            'car_id' => $r['vehicleReference']['objectId']
                        ]);
                    }
                }
                $i++;

                if (count($this->fetchResult) >= $this->fetchLimit) {
                    $resCount += count($this->fetchResult);
                    $this->save();
                    $this->fetchResult = [];
                }
            }
            
            $resCount += count($this->fetchResult);
            $this->save();
            $this->fetchResult = [];
        } else
            throw new \Exception("Nie znaleziono magazynów. Najpierw uruchom komendę pobierającą listę magazynów [tema:stock]", 99);
            
        $this->clearDataArrays();
        return ['fetched' => $resCount];
    }

    protected function getFieldsParams(): array
    {
        return [
            'car_id' => ['sourceField' => 'car_id', 'type' => ParameterType::STRING],
            'stock_id' => ['sourceField' => 'stock_id', 'type' => ParameterType::STRING],
            'source' => ['sourceField' => 'source', 'type' => ParameterType::STRING],
        ];
    }

    private function getStocks()
    {
        $q = "SELECT stock_id FROM tema_stock WHERE category != 'workshop'
            AND (name NOT LIKE '%części%' OR name NOT LIKE '%HURT%')
            AND source = :source";
        return $this->db->fetchFirstColumn($q, ['source' => $this->source->getName()], ['source' => ParameterType::STRING]);
    }
}
