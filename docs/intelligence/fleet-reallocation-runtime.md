# Exécution OR-Tools depuis le SaaS

## Résultat utilisateur

L’écran `/intelligence/fleet-reallocation` permet désormais à un utilisateur
tenant-wide possédant `prediction.demo.review` de choisir D+1 à D+7 et de
demander un nouveau calcul. Laravel crée un registre tenant-scopé, place un job
sur la queue `intelligence`, exécute réellement Google OR-Tools en Python,
valide le JSON côté serveur, conserve la preuve privée et affiche le résultat.

Cette première exécution reste une démonstration synthétique honnête. Elle ne
constitue ni une inférence HGB, ni une validation sur l’historique RentFleet, ni
une consigne opérationnelle. Accepter une proposition ne déplace aucun véhicule
et conserve l’effet `NO_OPERATIONAL_ACTION`.

## Préparation locale Windows

Depuis PowerShell à la racine du dépôt :

```powershell
py -3.12 -m venv .venv-ortools
.\.venv-ortools\Scripts\python.exe -m pip install --disable-pip-version-check --requirement scripts\intelligence\requirements-fleet-reallocation.txt
.\.venv-ortools\Scripts\python.exe -m pip check
.\.venv-ortools\Scripts\python.exe -c "import ortools; print(ortools.__version__)"
```

La dernière commande doit afficher exactement `9.15.6755`. Définir ensuite
dans `.env` le chemin absolu de cet interpréteur :

```dotenv
INTELLIGENCE_PYTHON_BINARY=C:\Users\pc\Desktop\MDS\s4\pfe\.venv-ortools\Scripts\python.exe
QUEUE_CONNECTION=database
```

Après `php artisan optimize:clear` et `php artisan migrate`, garder ce worker
ouvert dans un second terminal :

```powershell
php artisan queue:work --queue=intelligence --tries=1 --timeout=35
```

## Vérification

1. Ouvrir `http://rentfleet-pfe.test/intelligence/fleet-reallocation`.
2. Choisir un horizon puis cliquer sur **Générer une proposition**.
3. Vérifier le passage `En attente` → `Calcul en cours` → `Calcul terminé`.
4. Ouvrir la proposition associée et vérifier `OPTIMAL`, le temps solveur, la
   demande servie et les déplacements synthétiques.
5. Télécharger le JSON privé puis accepter ou rejeter pour la démonstration.
6. Vérifier qu’aucune réservation, véhicule, maintenance ou facture n’a changé.

En cas d’environnement Python incomplet, le registre termine en échec avec un
code borné comme `ORTOOLS_DEPENDENCY_MISSING` ou
`ORTOOLS_VERSION_MISMATCH`. Aucun stderr brut ni chemin sensible n’est stocké.
Une exécution restée active plus de dix minutes après un arrêt brutal est
fermée en `RUN_STALE_RECOVERED` lors de la demande suivante, afin de ne pas
bloquer durablement l’entreprise.

## Tests

- le test Python lance réellement `SimpleMinCostFlow`, vérifie les invariants,
  le contrat fermé et l’absence de périmètre tenant dans le sous-processus ;
- le test Laravel simule uniquement la frontière processus afin de vérifier la
  queue, le RBAC, l’isolation tenant, l’import, l’état final et l’absence
  d’écriture opérationnelle ;
- la CI scientifique installe l’environnement OR-Tools figé et exécute le test
  runtime réel.

Sources primaires :

- [Google OR-Tools — Minimum Cost Flows](https://developers.google.com/optimization/flow/mincostflow)
- [Laravel 12 — Queues](https://laravel.com/docs/12.x/queues)
- [Laravel 12 — Processes](https://laravel.com/docs/12.x/processes)
