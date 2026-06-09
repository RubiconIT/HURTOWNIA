<?php

namespace App\Service\TaskReporter;

use App\Entity\ApiFetchError;
use App\EntityWarehouse\JobHistory;
use App\Repository\ApiFetchErrorRepository;
use App\Repository\EntityWarehouse\JobHistoryRepository;
use App\Service\Alert\Alert;

class TaskReporter
{
    private $errorRepo;
    private $jobHistory;
    private $alert;
    private ?string $commandName = null;

    public function __construct(ApiFetchErrorRepository $errorRepo, JobHistoryRepository $jobHistory, Alert $alert)
    {
        $this->errorRepo = $errorRepo;
        $this->jobHistory = $jobHistory;
        $this->alert = $alert;
    }

    public function setCommandName(string $commandName): void
    {
        $this->commandName = $commandName;
    }

    public function reportApiFetchError(string $connectionName, string $path, int $httpCode)
    {
        $error = new ApiFetchError;
        $error
            ->setEndpoint($path)
            ->setSource($connectionName)
            ->setHttpCode($httpCode)
            ->setCommand($this->commandName);
        $this->errorRepo->save($error, true);
    }

    public function setStart($command, $params)
    {
        $history = new JobHistory;
        $history->setCommand($command)
                ->setParameter(json_encode($params));

        return $this->jobHistory->save($history, true);
    }

    public function setError(int $id, string $msg, string $command)
    {
        $this->jobHistory->setError($id, $msg);
        $this->sendErrorReport($command, $msg);
    }

    public function setEnd(int $id)
    {
        return $this->jobHistory->setEnd($id);
    }

    public function sendErrorReport(string $command, string $msg)
    {
        $this->alert->sendCommandAlert($command, $msg);
    }

    public function sendFetchErrors(string $commandName)
    {
        $errors = [];
        $problems = [];
        foreach ($this->errorRepo->findBy(['command' => $commandName]) as $e) {
            $errors[] = [
                'source' => $e->getSource(),
                'endpoint' => $e->getEndpoint(),
                'http_code' => $e->getHttpCode(),
                'time' => $e->getTime()->format('Y-m-d H:i:s')
            ];

            $problems[] = [
                'source' => $e->getSource(),
                'endpoint' => $e->getEndpoint(),
                'http_code' => $e->getHttpCode(),
                'time' => $e->getTime()->format('Y-m-d H:i:s')
            ];
        }
        
        if (!empty($errors)) {
            // $this->alert->sendFetchErrors($errors);
            return json_encode($problems, JSON_PRETTY_PRINT);
        }

        return '';
    }
}