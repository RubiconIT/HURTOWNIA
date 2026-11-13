<?php

namespace App\Entity\Rogowiec;

use App\Repository\Rogowiec\OrgUnitRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;

#[ORM\Entity(repositoryClass: OrgUnitRepository::class)]
#[Table(name: 'rogowiec_sale_unit')]
#[Index(name: "source_idx", fields: ["source"])]
#[ORM\UniqueConstraint(name: "resource_id_source_unique", columns: ["resource_id", "source"])]
class SaleUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $source = null;

    #[ORM\Column]
    private ?int $dms_id = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\Column(length: 200)]
    private ?string $label = null;

    #[ORM\Column(length: 10)]
    private ?string $resource_id = null;
}