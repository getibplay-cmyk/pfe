# Preuves du lot 06E — UX et autorisations visibles

## Contrats couverts

- navigation desktop/mobile issue d’une source unique et filtrée pour six rôles ;
- plateforme invisible et inaccessible aux utilisateurs tenant ;
- actions de mutation absentes pour Viewer/Auditor et routes directes en 403 ;
- Agency Manager borné à son agence dans listes, dashboard et audit ;
- profil sans route DELETE et champs de périmètre non modifiables ;
- changement obligatoire du mot de passe préservé ;
- libellés français centralisés avec repli sûr ;
- dashboard permission-scopé, sans identité, permis, secret ni valeurs d’audit ;
- filtres conservés dans la pagination.

## Commandes de preuve

```powershell
php artisan test tests/Feature/Lot06EUxNavigationAuthorizationTest.php
php artisan test tests/Feature/ProfileTest.php tests/Feature/Auth/PasswordUpdateTest.php
php artisan view:cache
php artisan route:list
php vendor/bin/pint --test
npm.cmd run build
```

## Résultats observés le 16 juillet 2026

- reconstruction et seeders : réussite exclusivement sur `rentfleet_test` ;
- test ciblé Lot 06E : **8 tests, 176 assertions**, réussite ;
- Profil + mot de passe + Lot 06E : **15 tests, 204 assertions**, réussite ;
- suite complète : **154 tests, 984 assertions**, réussite ;
- Pint correcteur et contrôle : réussite ;
- build Vite : **56 modules**, réussite ;
- compilation Blade : réussite ; inventaire final : **151 routes** ;
- `rentfleet:doctor` : 57 migrations, PostgreSQL 18.4, contraintes et stockage
  critiques réussis ; avertissement attendu pour l’environnement local ;
- `npm audit --omit=dev` : aucune vulnérabilité ;
- `composer audit --locked` : non conclu, Packagist indisponible par DNS
  (`curl error 6`) puis expiration de connexion (`curl error 28`).

Les routes `register`, `signup`, `storage/*` et `DELETE profile` sont absentes.
Aucun secret ou contenu documentaire privé n’a été copié dans cette preuve.
