<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\ContactMessage;
use App\Repository\ContactRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande reçue via le formulaire de contact.
 * Persistée AVANT l'envoi du mail de notification : si le mail échoue,
 * la demande n'est pas perdue.
 */
#[ORM\Entity(repositoryClass: ContactRequestRepository::class)]
#[ORM\Table(name: 'contact_request')]
#[ORM\Index(name: 'idx_contact_request_created_at', columns: ['created_at'])]
class ContactRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 20)]
        private string $motif,

        #[ORM\Column(length: 120)]
        private string $nom,

        #[ORM\Column(length: 120, nullable: true)]
        private ?string $societe,

        #[ORM\Column(length: 180)]
        private string $email,

        #[ORM\Column(type: Types::TEXT)]
        private string $message,

        // 45 caractères : suffisant pour une IPv6 complète
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $ip,

        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public static function fromDto(ContactMessage $dto, ?string $ip): self
    {
        return new self(
            motif: $dto->motif,
            nom: $dto->nom,
            societe: $dto->societe !== '' ? $dto->societe : null,
            email: $dto->email,
            message: $dto->message,
            ip: $ip,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getSociete(): ?string
    {
        return $this->societe;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
