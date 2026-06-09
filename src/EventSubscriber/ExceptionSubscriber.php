<?php

namespace App\EventSubscriber;

use App\Service\TaskReporter\TaskReporter;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class ExceptionSubscriber implements EventSubscriberInterface
{
    private $id = false;
    private $taskReporter;

    public function __construct(TaskReporter $taskReporter)
    {
        $this->taskReporter = $taskReporter;
    }

    public static function getSubscribedEvents(): array
    {
        if ($_ENV['APP_ENV'] === 'prod')
            return [
                ConsoleEvents::TERMINATE => [
                    ['setEnded', 1000]
                ],
                ConsoleEvents::COMMAND => [
                    ['setStarted', 1000]
                ],
                ConsoleEvents::ERROR => [
                    ['setError', 1000]
                ]
            ];
        else {
            dump('Korzystasz ze środowiska ' . $_ENV['APP_ENV'] . '. Żadne alerty nie będą przychodziły!' . PHP_EOL . 'Ustaw APP_ENV w pliku .env na prod');
            return [];
        }
    }

    public function setStarted(ConsoleCommandEvent $event)
    {
        $input = $event->getInput();
        $output = $event->getOutput();
        $command = $event->getCommand();

        $args = $input->getArguments() ?? [];

        $commandName = $command->getName();
        if ($commandName != 'cache:clear') {
            $this->taskReporter->setCommandName($commandName);
            $this->id = $this->taskReporter->setStart($commandName, $args);
            $output->writeln(sprintf('ZAPIS DO JOBS_HISTORY'));
        }
    }

    public function setEnded(ConsoleTerminateEvent $event)
    {
        if ($this->id == true) {
            
            $command = $event->getCommand();
            $problems = $this->taskReporter->sendFetchErrors($command->getName());
            if ($problems !== '') {
                dump($problems);
                $this->taskReporter->setError($this->id, $problems, $command->getName());
            }
            
            $this->taskReporter->setEnd($this->id);
            
            $output = $event->getOutput();
            $output->writeln(sprintf('KOŃCOWY ZAPIS DO JOBS_HISTORY'));
        }
    }

    public function setError(ConsoleErrorEvent $event)
    {
        $output = $event->getOutput();
        if ($this->id == true) {
            $e = $event->getError();
            $msg = $e->getMessage() . ". Linia: " . $e->getLine() . ".<br>" . str_replace(PHP_EOL, '<br>', $e->getTraceAsString());
            $cmd = $event->getCommand()->getName();
            $this->taskReporter->setError($this->id, $msg, $cmd);
            $output->writeln('');
            $output->writeln(sprintf('Bład  <info>%s</info>', $event->getError()));
        }
    }
}
