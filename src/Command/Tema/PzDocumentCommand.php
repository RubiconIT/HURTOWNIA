<?php

namespace App\Command\Tema;

use App\Command\BaseApiCommand;
use App\Entity\IConnection;
use App\Repository\ApiFetchErrorRepository;
use App\Repository\SourceAuthRepository;
use App\Repository\Tema\PzDocumentRepository;
use App\Repository\Tema\PzItemRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tema:pz',
    description: 'Pobiera dokumenty PZ i pozycje z nich. Parametry: nazwa api (opcja)',
)]
class PzDocumentCommand extends BaseApiCommand
{
    private $docRepo;
    private $itemRepo;
    protected $producerName = 'Tema';

    public function __construct(PzDocumentRepository $docRepo, PzItemRepository $itemRepo, SourceAuthRepository $apiAuthRepo, ApiFetchErrorRepository $errorRepo)
    {
        parent::__construct($apiAuthRepo, $errorRepo);
        $this->docRepo = $docRepo;
        $this->itemRepo = $itemRepo;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('api', InputArgument::OPTIONAL, 'Nazwa api (ALL lub brak nazwy dla wszystkich)', 'ALL');
        ;
    }

    protected function fetch(IConnection $api, SymfonyStyle &$io)
    {
        $this->itemRepo->setSource($api);
        $this->docRepo->setSource($api);
        $this->docRepo->addRelatedRepository($this->itemRepo, 'items');

        // Czyszczenie danych z zakresu dat
        $dateFrom = new \DateTime('2 weeks ago');
        $dateTo = new \DateTime();
        $this->docRepo->removeDateRangeTema($dateFrom, $dateTo);

        $fetchedRows = $this->docRepo->fetch();
        $io->info(sprintf("Pobrano %s rekordów", $fetchedRows['fetched']));

        unset($fetchedRows);
    }

    protected function clearTable()
    {
        // $this->docRepo->clearTable();
        // $this->itemRepo->clearTable();
    }
}