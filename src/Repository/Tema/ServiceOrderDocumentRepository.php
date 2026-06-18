<?php

namespace App\Repository\Tema;

use App\Repository\IApiRepository;
use Doctrine\DBAL\ParameterType;

class ServiceOrderDocumentRepository extends IApiRepository
{
    private string $endpoint = '/api/dms/v1/repair-orders/{branchId}';
    private $documentEndpoints = [];
    protected $table = 'tema_service_order_document';
    private const BATCH_SIZE = 10; // Number of simultaneous requests per batch
    protected $onDuplicateClause = 'ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        opening_date = VALUES(opening_date),
        closing_date = VALUES(closing_date),
        is_canceled = VALUES(is_canceled),
        net_value = VALUES(net_value),
        gross_value = VALUES(gross_value),
        stock_status = VALUES(stock_status),
        customer_id = VALUES(customer_id),
        service_handling_user_id = VALUES(service_handling_user_id),
        description = VALUES(description),
        source_order_number = VALUES(source_order_number),
        claim_number = VALUES(claim_number),
        insurer_id = VALUES(insurer_id),
        insurance_company_contribution = VALUES(insurance_company_contribution),
        source_order_id = VALUES(source_order_id)
    ';

    public function fetch(): array
    {
        $this->clearDataArrays();
        $this->getDocumentEndpointList();
        // $this->filterOutPossessed();
        $listCount = count($this->documentEndpoints);

        $documentItems = [];
        $endDocuments = [];
        $cars = [];

        if ($listCount) {
            echo "\nPobieram zapisy dokumentów";

            $totalBatches = (int)ceil($listCount / self::BATCH_SIZE);

            for ($i = 0; $i < $listCount; $i += self::BATCH_SIZE) {
                $batchNumber = (int)($i / self::BATCH_SIZE) + 1;
                echo "\nBatch $batchNumber / $totalBatches";

                $batchEndpoints = array_slice($this->documentEndpoints, $i, self::BATCH_SIZE);
                $urls = array_column($batchEndpoints, 'getUrl');

                // Fetch multiple endpoints in parallel
                $docs = $this->httpClient->requestMulti($this->source, $urls);

                foreach ($docs as $docRaw) {
                    $doc = $this->decodeResponseFromRaw($docRaw);
                    if (empty($doc))
                        continue;

                    $this->collectItems($doc, $documentItems);
                    $this->collectEndDocuments($doc, $endDocuments);
                    $this->collectCar($doc, $cars);
                    array_push($this->fetchResult, $doc);

                    if (count($this->fetchResult) >= $this->fetchLimit) {
                        $this->save();
                        $this->fetchResult = [];
                        $this->relatedRepositories['items']->saveItems($documentItems);
                        $documentItems = [];
                        $this->relatedRepositories['endDocs']->saveDocs($endDocuments);
                        $endDocuments = [];
                        $this->relatedRepositories['cars']->saveCars($cars);
                        $cars = [];

                        
                    }
                }
            }

            $this->save();
            $this->fetchResult = [];
            $this->relatedRepositories['items']->saveItems($documentItems);
            unset($documentItems);
            $this->relatedRepositories['endDocs']->saveDocs($endDocuments);
            unset($endDocuments);
            $this->relatedRepositories['cars']->saveCars($cars);
            unset($cars);

            
        }

        return [
            'fetched' => $listCount,
        ];
    }

    private function filterOutPossessed()
    {
        $twoMonthsAgo = (new \DateTime())->modify('-2 months')->format('Y-m-01 00:00:00');
        $possessed = $this->db->fetchFirstColumn(
            "SELECT doc_id FROM $this->table WHERE source = :source AND (stock_status = 'ended' OR closing_date != '0001-01-01 00:00:00') AND closing_date <= '$twoMonthsAgo'",
            ['source' => $this->source->getName()],
            ['source' => ParameterType::STRING]
        );

        echo PHP_EOL . 'Możliwe do pobrania dokumenty: ' . count($this->documentEndpoints) . "\033[0m" . PHP_EOL;
        echo PHP_EOL . 'Posiadane dokumenty: ' . count($possessed) . "\033[0m" . PHP_EOL;

        if (!empty($possessed)) {
            // [
            //     {
            //         "objectId": "string",
            //         "getUrl": "string"
            //     }
            // ]

            $this->documentEndpoints = array_filter($this->documentEndpoints, function($doc) use ($possessed) {
                return !in_array($doc['objectId'], $possessed);
            });
            // reset keys
            $this->documentEndpoints = array_values($this->documentEndpoints);
        }
        echo PHP_EOL . 'Do pobrania dokumenty: ' . count($this->documentEndpoints) . "\033[0m" . PHP_EOL;
    }

    // Helper to decode raw response (since fetchApiResult is not used directly)
    private function decodeResponseFromRaw($raw): array
    {
        if (str_contains($raw, 'Us³ugaPunktSprzedazy')) {
            $raw = preg_replace('/Us³ugaPunktSprzedazy/', 'UsługaPunktSprzedazy', $raw);
        }
        return json_decode($raw, true) ?? [];
    }

    private function getDocumentEndpointList()
    {
        $stocks = $this->getStocks();
        $stocksCount = count($stocks);

        if ($stocksCount) {
            echo "\nPobieranie endpointów dla oddziałów";
            $i = 1;

            foreach ($stocks as $stock) {
                echo "\nNr stocku $stock ----> $i/$stocksCount";
                $i++;

                $url = str_replace('{branchId}', $stock, $this->endpoint);
                $res = $this->fetchApiResult($url);
                if (empty($res))
                    continue;

                $this->documentEndpoints = array_merge($this->documentEndpoints, $res);
            }
        } else
            throw new \Exception("Nie żadnych jednostek organizacyjnych. Najpierw uruchom komendę pobierającą listę jednostek [tema:stock]", 99);
    }

    protected function getFieldsParams(): array
    {
        return [
            'doc_id' => ['sourceField' => 'id', 'type' => ParameterType::STRING],
            'name' => ['sourceField' => 'name', 'type' => ParameterType::STRING],
            'opening_date' => ['sourceField' => 'openingDate', 'type' => ParameterType::STRING, 'format' => ['date' => 'Y-m-d H:i:s']],
            'closing_date' => ['sourceField' => 'closingDate', 'type' => ParameterType::STRING, 'format' => ['date' => 'Y-m-d H:i:s']],
            'is_canceled' => ['sourceField' => 'isCanceled', 'type' => ParameterType::INTEGER, 'format' => ['int' => true]],
            'net_value' => ['sourceField' => 'netValue', 'type' => ParameterType::STRING],
            'gross_value' => ['sourceField' => 'grossValue', 'type' => ParameterType::STRING],
            'stock_status' => ['sourceField' => 'stockStatus', 'type' => ParameterType::STRING],
            'customer_id' => ['sourceField' => 'customerId', 'type' => ParameterType::STRING],
            'service_handling_user_id' => ['sourceField' => 'serviceHandlingUserId', 'type' => ParameterType::STRING],
            'description' => ['sourceField' => 'description', 'type' => ParameterType::STRING],
            'source_order_number' => ['sourceField' => 'sourceOrderNumber', 'type' => ParameterType::STRING],
            'source_order_id' => ['sourceField' => 'sourceOrderId', 'type' => ParameterType::STRING],
            'claim_number' => ['sourceField' => 'claimNumber', 'type' => ParameterType::STRING],
            'insurer_id' => ['sourceField' => 'insurerId', 'type' => ParameterType::STRING],
            'insurance_company_contribution' => ['sourceField' => 'insuranceCompanyContribution', 'type' => ParameterType::STRING],
            'source' => ['sourceField' => 'source', 'type' => ParameterType::STRING],
        ];
    }

    private function collectItems(array &$doc, array &$documentItems)
    {
        foreach ($doc['items'] as $item) {
            $item['doc_id'] = $doc['id'];
            array_push($documentItems, $item);
        }
        unset($doc['items']);
    }

    private function collectEndDocuments(array &$doc, array &$endDocuments)
    {
        foreach ($doc['documents'] as $item) {
            $item['doc_id'] = $doc['id'];
            array_push($endDocuments, $item);
        }
        unset($doc['documents']);
    }

    private function collectCar(array &$doc, array &$cars)
    {
        $doc['vehicle']['doc_id'] = $doc['id'];
        array_push($cars, $doc['vehicle']);
        unset($doc['vehicle']);
    }

    private function getStocks()
    {
        $q = "SELECT stock_id FROM tema_stock WHERE source = :source AND category = 'workshop'";
        return $this->db->fetchFirstColumn($q, ['source' => $this->source->getName()], ['source' => ParameterType::STRING]);
    }

    protected function clearDataArrays()
    {
        $this->fetchResult = [];
        $this->documentEndpoints = [];
    }
}
