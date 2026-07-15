<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ContactMessage;
use App\Entity\ContactRequest;
use App\Service\SpamGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly string $contactTo,
        private readonly string $contactFrom,
    ) {
    }

    #[Route('/mercure-ia', name: 'app_landing', methods: ['GET'])]
    public function landing(): Response
    {
        return $this->render('landing.html.twig');
    }

    /**
     * La racine accueillera la future landing Groupe Horao.
     * En attendant : redirection TEMPORAIRE (302, surtout pas 301 — un 301
     * serait mis en cache par les navigateurs et les moteurs, et gênerait la
     * mise en place de la vraie page d'accueil).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('app_landing', [], Response::HTTP_FOUND);
    }

    #[Route('/contact', name: 'app_contact', methods: ['POST'])]
    public function submit(
        Request $request,
        SpamGuard $spamGuard,
        RateLimiterFactory $contactFormLimiter, // limiteur "contact_form" (framework.yaml)
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): JsonResponse {
        // 1) CSRF — jeton généré par Twig au rendu de la page
        if (!$this->isCsrfTokenValid('contact', $request->request->getString('_token'))) {
            return $this->json(
                ['ok' => false, 'error' => 'Votre session a expiré, rechargez la page puis réessayez.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        // 2) Anti-spam (honeypot + time-trap).
        //    On répond un faux succès : inutile d'indiquer aux bots qu'ils sont détectés.
        if ($spamGuard->isSpam($request)) {
            $logger->info('Contact : soumission écartée par SpamGuard.', [
                'ip' => $request->getClientIp(),
            ]);

            return $this->json(['ok' => true]);
        }

        // 3) Rate limiting par IP (3 envois / heure, fenêtre glissante)
        $limiter = $contactFormLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(
                ['ok' => false, 'error' => 'Trop de demandes envoyées, merci de réessayer dans une heure.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        // 4) Validation stricte côté serveur
        $dto = ContactMessage::fromRequest($request);
        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] ??= $violation->getMessage();
            }

            return $this->json(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 5) Persistance AVANT l'envoi du mail : si le mail échoue,
        //    la demande reste en base (rien n'est perdu).
        $contactRequest = ContactRequest::fromDto($dto, $request->getClientIp());
        $entityManager->persist($contactRequest);
        $entityManager->flush();

        // 6) Notification mail — la destination ne vit qu'en config serveur,
        //    jamais dans le HTML. Les en-têtes sont encodés par symfony/mime
        //    (pas d'injection CRLF possible via nom/e-mail).
        $email = (new Email())
            ->from(new Address($this->contactFrom, 'Groupe Horao — Formulaire de contact'))
            ->to($this->contactTo)
            ->replyTo(new Address($dto->email, $dto->nom))
            ->subject(sprintf('[Mercure-IA] [%s] Nouvelle demande de %s', $dto->motif, $dto->nom))
            ->text(implode("\n", [
                'Nouvelle demande reçue via la landing page Mercure-IA',
                '----------------------------------------------------',
                'Référence : #'.$contactRequest->getId(),
                'Motif    : '.$dto->motif,
                'Nom      : '.$dto->nom,
                'Société  : '.($dto->societe !== '' ? $dto->societe : '(non renseignée)'),
                'E-mail   : '.$dto->email,
                'IP       : '.($request->getClientIp() ?? 'inconnue'),
                '',
                'Message :',
                $dto->message,
            ]));

        try {
            $mailer->send($email);
        } catch (\Throwable $e) {
            // La demande est déjà en base : on log l'échec du mail sans le
            // signaler comme une erreur au visiteur (et sans fuiter de détails).
            $logger->error('Contact : demande #{id} enregistrée mais échec d’envoi du mail.', [
                'id' => $contactRequest->getId(),
                'exception' => $e,
            ]);
        }

        return $this->json(['ok' => true]);
    }
}
