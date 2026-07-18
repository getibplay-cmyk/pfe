# Preuves du lot 06F-C2 — cycle assurance

## Remédiation autorisée

La police de démonstration historique `#1` a reçu une preuve documentaire
fictive, privée et non contractuelle par le mécanisme documentaire commun. Le
fichier réellement stocké a servi au calcul de la taille et de l’empreinte
SHA-256 de sa version courante. L’audit technique créé porte l’identifiant
`#79` et l’action `insurance.policy.document.remediated`.

La remédiation n’a modifié ni le statut, ni la période, ni la garantie de cette
police. Le sinistre historique associé est resté `under_review` avec
`reviewed_at` nul. Aucun numéro complet, référence assureur, chemin privé ou
contenu documentaire n’est reproduit dans cette preuve.

## Contrats couverts

- cycle des compagnies sans suppression physique ;
- cycle `draft → active → expired` et `draft/active → cancelled` ;
- activation conditionnée par garantie et preuve privée valides ;
- renouvellement non destructif et historique append-only ;
- exclusion PostgreSQL des chevauchements actifs ;
- garanties mutables uniquement sur un brouillon et archivées logiquement ;
- incident daté dans la période de couverture et cycle humain du sinistre ;
- documents privés versionnés, contrôlés par policy et téléchargements audités ;
- expiration quotidienne idempotente ;
- listes filtrées, paginées et bornées à l’agence ;
- alertes assurance tenant/agence-scopées sur le dashboard.

## Commandes de preuve

```powershell
php artisan migrate:status
php artisan migrate
php artisan rentfleet:doctor
php artisan migrate:fresh --seed --env=testing
php artisan test tests/Feature/Lot06FC2InsuranceCompletionTest.php
php artisan test
php vendor/bin/pint --test
npm.cmd run build
composer validate
composer audit --locked --no-interaction
npm.cmd audit --omit=dev
php artisan route:list
git diff --check
```

## Résultats observés le 18 juillet 2026

- précontrôles `rentfleet` : zéro incompatibilité et preuve privée intègre ;
- migration C2 : appliquée en batch `11` sans reconstruction de `rentfleet` ;
- PostgreSQL : 12 contraintes C2 principales, 5 triggers et 3 index présents ;
- reconstruction et seeders : réussite exclusivement sur `rentfleet_test` ;
- test ciblé C2 : **6 tests, 59 assertions**, réussite ;
- régressions ciblées : **65 tests, 505 assertions**, réussite ;
- suite complète : **199 tests, 1 362 assertions**, réussite ;
- doctor : PostgreSQL 18.4, 62 migrations, 5/5 triggers assurance, réussite ;
- Pint : réussite ; build Vite : 56 modules, réussite ;
- Composer validate : réussite ; Composer audit : aucun advisory ;
- npm audit de production : aucune vulnérabilité ;
- compilation Blade : réussite ; inventaire : 191 routes, aucune route
  `storage/*` ;
- `.env` et `.env.testing` restent ignorés et non suivis.

Les reconstructions destructives ont été exécutées exclusivement sur
`rentfleet_test`.
