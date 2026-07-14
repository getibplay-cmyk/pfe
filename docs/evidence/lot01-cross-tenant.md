# Preuve — isolation cross-tenant du lot 01

Commande exécutée sur la base dédiée `rentfleet_test` :

```powershell
php artisan test
```

Résultat observé avant validation finale : **40 tests réussis, 113 assertions**.

Les scénarios couvrent notamment :

- lecture d’un autre tenant refusée ;
- édition, suppression et route model binding d’une agence étrangère refusés ;
- injection de `tenant_id` rejetée ;
- compte inactif refusé à la connexion ;
- Tenant Owner limité à son tenant ;
- Agency Manager limité à son agence ;
- platform admin limité à ses routes dédiées ;
- création d’un audit tenant ;
- absence de mot de passe dans l’audit ;
- vérification explicite de PostgreSQL et de `rentfleet_test`.
- création de deux tenants, trois agences et utilisateurs pour les rôles initiaux.
