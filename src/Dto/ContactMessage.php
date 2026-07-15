<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire de contact, validées par le composant Validator.
 * Les maxlength côté HTML ne sont qu'un confort : la vérité est ici, côté serveur.
 */
final class ContactMessage
{
    public const MOTIFS = ['partenariat', 'investisseur', 'autre'];

    #[Assert\NotBlank(message: 'Le motif de contact est requis.')]
    #[Assert\Choice(choices: self::MOTIFS, message: 'Motif de contact invalide.')]
    public string $motif = '';

    #[Assert\NotBlank(message: 'Votre nom est requis.')]
    #[Assert\Length(max: 120, maxMessage: 'Le nom ne peut dépasser {{ limit }} caractères.')]
    public string $nom = '';

    #[Assert\Length(max: 120, maxMessage: 'Le nom de société ne peut dépasser {{ limit }} caractères.')]
    public string $societe = '';

    #[Assert\NotBlank(message: 'Votre adresse e-mail est requise.')]
    #[Assert\Email(message: 'Cette adresse e-mail n’est pas valide.')]
    #[Assert\Length(max: 180, maxMessage: 'L’adresse e-mail ne peut dépasser {{ limit }} caractères.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Votre message est requis.')]
    #[Assert\Length(
        min: 10,
        max: 3000,
        minMessage: 'Votre message est trop court (minimum {{ limit }} caractères).',
        maxMessage: 'Votre message ne peut dépasser {{ limit }} caractères.',
    )]
    public string $message = '';

    public static function fromRequest(Request $request): self
    {
        $dto = new self();
        $dto->motif = trim($request->request->getString('motif'));
        $dto->nom = trim($request->request->getString('nom'));
        $dto->societe = trim($request->request->getString('societe'));
        $dto->email = trim($request->request->getString('email'));
        $dto->message = trim($request->request->getString('message'));

        return $dto;
    }
}
