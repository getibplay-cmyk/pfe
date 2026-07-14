# Preuve — contrainte d’exclusion du lot 03

## Définition contrôlée

```sql
SELECT conname, pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conname = 'vehicle_blocks_no_active_overlap_excl';
```

Résultat attendu : exclusion GiST sur `tenant_id`, `vehicle_id` et
`tstzrange(starts_at, ends_at, '[)')`, limitée aux lignes `status = 'active'`.

Le test `test_postgresql_exclusion_constraint_blocks_direct_invalid_insert_with_23p01`
insère un bloc chevauchant directement avec `DB::table`, sans passer par le
service de disponibilité. PostgreSQL retourne `SQLSTATE 23P01`.

Les tests d’action couvrent également : chevauchement exact, partiel au début,
partiel à la fin, intervalle contenu, intervalle englobant, créneaux consécutifs,
véhicules différents, tenants différents, traduction en erreur métier, absence
de confirmation partielle et libération après annulation.

## Test réellement concurrent à deux sessions

`RefreshDatabase` maintient les fixtures PHPUnit dans une transaction non
committée, donc deux connexions externes ne peuvent pas les voir de manière
fiable dans ce runner. Le scénario suivant est reproductible après
`php artisan migrate:fresh --seed --env=testing`. Choisir un véhicule actif et
remplacer les quatre identifiants à partir de la base de test.

Session A :

```sql
BEGIN;
INSERT INTO vehicle_blocks
    (tenant_id, agency_id, vehicle_id, block_type, starts_at, ends_at, status, created_at, updated_at)
VALUES
    (:tenant_id, :agency_id, :vehicle_id, 'manual',
     '2026-09-01 09:00:00+01', '2026-09-01 12:00:00+01', 'active', now(), now());
-- ne pas committer immédiatement
```

Session B, pendant que A est ouverte :

```sql
BEGIN;
INSERT INTO vehicle_blocks
    (tenant_id, agency_id, vehicle_id, block_type, starts_at, ends_at, status, created_at, updated_at)
VALUES
    (:tenant_id, :agency_id, :vehicle_id, 'manual',
     '2026-09-01 10:00:00+01', '2026-09-01 13:00:00+01', 'active', now(), now());
```

La session B attend la résolution de A. Après `COMMIT` dans A, B échoue avec
`23P01 exclusion_violation`. Exécuter `ROLLBACK` dans B puis supprimer le bloc
de preuve si la base de test doit être réutilisée. Une seule ligne active reste.

## Validation

Commande :

```powershell
php artisan test
```

Résultat validé : **67 tests réussis, 215 assertions**, dont **18 tests et
67 assertions** propres au lot 03.
