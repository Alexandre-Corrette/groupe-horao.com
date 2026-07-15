<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Filtre anti-spam sans dépendance externe :
 *  - honeypot : le champ "website" est hors écran, un humain ne le remplit jamais ;
 *  - time-trap : "form_ts" est horodaté côté serveur au rendu de la page ;
 *    une soumission < 3 s (bot instantané) ou > 1 h (rejeu) est rejetée.
 *
 * En cas de doute persistant, ajouter une couche Turnstile / Friendly Captcha
 * (vérification du jeton ici même, via HttpClient).
 */
final class SpamGuard
{
    private const MIN_DELAY_MS = 3_000;
    private const MAX_DELAY_MS = 3_600_000;

    public function isSpam(Request $request): bool
    {
        // 1) Honeypot
        if ('' !== trim($request->request->getString('website'))) {
            return true;
        }

        // 2) Time-trap
        $ts = $request->request->get('form_ts');
        if (!is_numeric($ts)) {
            return true;
        }

        $elapsedMs = (int) round(microtime(true) * 1000) - (int) $ts;

        return $elapsedMs < self::MIN_DELAY_MS || $elapsedMs > self::MAX_DELAY_MS;
    }
}
