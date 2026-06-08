<?php

namespace App\Entity\Rogowiec;

use App\Repository\Rogowiec\CarsSoldRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CarsSoldRepository::class)]
#[ORM\Table(name: 'rogowiec_car_invoices')]
#[ORM\UniqueConstraint(name: "car_invoices_unique", columns: ["source", "vin", "fv_numer", "wartosc"])]
#[ORM\Index(name: "source_idx", columns: ["source"])]
#[ORM\Index(name: "vin_idx", columns: ["vin", "fv_numer"])]
#[ORM\Index(name: "fv_data_idx", columns: ["fv_data"])]
#[ORM\Index(name: "fetch_date_idx", columns: ["fetch_date"])]
class CarInvoices
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $source = null;

    #[ORM\Column]
    private ?int $vehicle_id = null;

    #[ORM\Column(length: 17)]
    private ?string $vin = null;

    #[ORM\Column(length: 50)]
    private ?string $fv_numer = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $fv_data = null;
    
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $fv_data_wplyw = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $wartosc = null;

    #[ORM\Column(length: 10)]
    private ?string $faktura_rodzaj = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $kod_typu_korekty = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $typ_korekty = null;
 
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $fetch_date = null;
}