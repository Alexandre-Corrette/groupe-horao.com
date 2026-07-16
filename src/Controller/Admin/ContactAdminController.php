<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ContactRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ContactAdminController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/admin/contacts', name: 'app_admin_contacts', methods: ['GET'])]
    public function index(ContactRequestRepository $repository, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $total = $repository->count([]);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $contacts = $repository->findBy(
            [],
            ['createdAt' => 'DESC'],
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        return $this->render('admin/contacts.html.twig', [
            'contacts' => $contacts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * Export CSV complet (séparateur ; pour ouverture directe dans Excel FR).
     */
    #[Route('/admin/contacts/export', name: 'app_admin_contacts_export', methods: ['GET'])]
    public function export(ContactRequestRepository $repository): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($repository): void {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Date', 'Motif', 'Nom', 'Société', 'E-mail', 'IP', 'Message'], ';');
            foreach ($repository->findBy([], ['createdAt' => 'DESC']) as $c) {
                fputcsv($out, [
                    $c->getId(),
                    $c->getCreatedAt()->format('d/m/Y H:i'),
                    $c->getMotif(),
                    $c->getNom(),
                    $c->getSociete() ?? '',
                    $c->getEmail(),
                    $c->getIp() ?? '',
                    $c->getMessage(),
                ], ';');
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="contacts-mercure-ia.csv"');

        return $response;
    }
}
