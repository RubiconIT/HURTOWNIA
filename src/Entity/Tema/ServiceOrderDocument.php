<?php

namespace App\Entity\Tema;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[ORM\Entity(repositoryClass: WzDocumentRepository::class)]
#[Table(name: 'tema_service_order_document')]
#[UniqueConstraint(name: "source_docId_idx", fields: ["source", "doc_id"])]
#[Index(name: "source_openingDate_idx", fields: ["source", "openingDate"])]
#[Index(name: "source_closingDate_idx", fields: ["source", "closingDate"])]
#[Index(name: "source_docId_isCanceled_stockStatus_idx", fields: ["source", "doc_id", "isCanceled", "stockStatus"])]
class ServiceOrderDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $source = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $doc_id = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $openingDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $closingDate = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private ?bool $isCanceled = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $netValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $grossValue = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $stockStatus = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $serviceHandlingUserId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sourceOrderNumber = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $sourceOrderId = null;

    #[ORM\Column(length: 50, nullable: true)]
    public readonly ?string $claimNumber;

    #[ORM\Column(length: 20, nullable: true)]
    public readonly ?string $insurerId;

    #[ORM\Column(length: 30, nullable: true)]
    public readonly ?string $insuranceCompanyContribution;
}
