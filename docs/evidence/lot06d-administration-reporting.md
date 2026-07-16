# Preuves du lot 06D — administration SaaS et reporting minimal

## Contrats vérifiés

- provisioning transactionnel tenant + agence + Tenant Owner + paramètres ;
- mot de passe initial aléatoire, affiché une fois, réponse `no-store` et
  remplacement obligatoire ;
- suspension motivée, auditée, révocation de session et refus de connexion ;
- administration tenant/agence/utilisateur bornée par rôle et périmètre ;
- protection du dernier owner, de la dernière agence et des dépendances actives ;
- export CSV streamé, filtré, UTF-8, sans données sensibles et protégé contre
  l’injection de formule ;
- rapport minimal tenant/agence-scopé avec montants décimaux exacts.

## Commandes de preuve

Les commandes suivantes doivent cibler `rentfleet_test` pour toute opération
destructive :

```powershell
php artisan migrate:fresh --seed --env=testing --no-interaction
php artisan test tests/Feature/Lot06DSaasAdministrationReportingTest.php
php artisan test
php vendor/bin/pint --test
npm.cmd run build
php artisan rentfleet:doctor
php artisan route:list
```

## Résultats observés le 15 juillet 2026

- `migrate:fresh --seed --env=testing` : réussite sur `rentfleet_test` ;
- suite ciblée 06D : **9 tests, 108 assertions**, réussite ;
- suite complète : **146 tests, 810 assertions**, réussite ;
- Pint : réussite ; build Vite : **56 modules**, réussite ;
- `rentfleet:doctor` : contrôles techniques réussis, avertissement attendu pour
  l’environnement local ; mode debug conforme à ce profil local ;
- `npm audit --omit=dev` : aucune vulnérabilité ;
- `composer audit --locked` : non conclu, résolution DNS de Packagist indisponible
  (`curl error 6/28`). Le contrôle hors ligne est également refusé par Composer ;
  ce prérequis réseau n’est pas transformé en faux résultat positif.

Aucun mot de passe, token, contenu documentaire privé ou valeur de `.env` n’a
été copié dans cette preuve.
