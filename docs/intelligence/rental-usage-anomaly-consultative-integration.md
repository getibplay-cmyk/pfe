# Usages de location atypiques — intégration consultative v1.0

## Décision

Cette tranche consomme directement un snapshot privé `rentfleet-real-returns-v1.1.0`. Elle classe des retours à vérifier et ne prédit pas une fraude, une faute, un dommage ou une responsabilité.

- modèle principal : `robust_mad_top2` v1.0.0 ;
- challenger : `isolation_forest` v1.0.0, comparaison uniquement ;
- budgets : 0,5 %, 1 % par défaut, 2 % ;
- calcul : CPU, une seule thread numérique et graine `20260824` ;
- historique minimal : 200 lignes, sinon abstention persistée ;
- effet constant : `NO_OPERATIONAL_ACTION`.

## Score principal

Pour chacune des trois variables `late_hours`, `km_per_day` et `fuel_drop_pct` :

1. calculer la médiane du batch ;
2. calculer la MAD et une échelle de secours fondée sur l’IQR ;
3. conserver uniquement l’écart robuste positif à la médiane ;
4. prendre les deux plus grands écarts ;
5. utiliser leur moyenne comme indice de classement.

Le résultat explique chaque candidat avec ses deux facteurs dominants. L’indice n’est pas calibré comme une probabilité. La MAD est une mesure robuste aux valeurs aberrantes ; voir la [documentation SciPy](https://docs.scipy.org/doc/scipy/reference/generated/scipy.stats.median_abs_deviation.html).

Isolation Forest est ajusté sur `log1p` des trois variables avec 300 arbres, `n_jobs=1` et une graine fixe. Son score brut sert à calculer les rangs et le Jaccard avec le principal. Il ne sélectionne jamais à la place de `robust_mad_top2`. La [documentation officielle scikit-learn](https://scikit-learn.org/stable/modules/generated/sklearn.ensemble.IsolationForest.html) précise que les scores plus faibles de `score_samples` correspondent aux observations plus anormales et que `random_state` contrôle la reproductibilité.

## Flux SaaS

1. un utilisateur autorisé crée l’export réel v1.1 existant ;
2. il lance explicitement une analyse depuis `/intelligence/rental-usage-anomalies` ;
3. la queue `intelligence` lit le snapshot sur le disque privé et vérifie SHA-256, taille, schéma et nombre de lignes ;
4. Python reçoit uniquement le chemin du CSV pseudonymisé, sans secret Laravel ni accès PostgreSQL ;
5. Laravel valide le JSON fermé et rattache les clés pseudonymes aux contrats dans le périmètre tenant/agence ;
6. PostgreSQL enregistre l’exécution et l’union des tops 2 % des deux classements ;
7. l’écran affiche uniquement le top principal du budget choisi ;
8. chaque revue humaine ajoute un événement, sans écraser les précédents.

Les traitements longs passent par une queue Laravel, conformément à la [documentation officielle Laravel](https://laravel.com/docs/12.x/queues).

## Garde-fous PostgreSQL

- une exécution ne peut évoluer que `queued → running → succeeded|failed` ;
- une exécution terminale est immuable ;
- les résultats acceptent uniquement les trois budgets imbriqués et l’effet `NO_OPERATIONAL_ACTION` ;
- résultats et revues refusent `UPDATE` et `DELETE` par trigger `BEFORE` ;
- une revue n’est possible que sur un résultat principal du top 2 % et une exécution réussie ;
- les clés étrangères composées imposent tenant, agence, contrat, exécution et auteur cohérents.

PostgreSQL permet à un trigger `BEFORE` de refuser une modification avant son application ; voir [`CREATE TRIGGER`](https://www.postgresql.org/docs/18/sql-createtrigger.html).

Le code d’exécution et de revue ne référence aucune table de sanction, frais, facture, paiement, dommage ou mutation contractuelle. Les tests comparent une empreinte logique des tables métier avant et après le classement puis la revue.

## Installation Windows PowerShell

Depuis la racine du projet :

```powershell
py -3.12 -m venv .venv-anomaly-v1

& .\.venv-anomaly-v1\Scripts\python.exe -m pip install `
  --requirement .\scripts\intelligence\requirements-rental-usage-anomaly-runtime.txt

& .\.venv-anomaly-v1\Scripts\python.exe -m pip check
```

Configuration locale :

```dotenv
RENTFLEET_ANOMALY_V1_ENABLED=true
ANOMALY_V1_PYTHON_BINARY=C:/Users/pc/Desktop/MDS/s4/pfe/.venv-anomaly-v1/Scripts/python.exe
```

Puis :

```powershell
php artisan optimize:clear
php artisan migrate --force
php artisan queue:work --queue=intelligence,default --tries=1 --timeout=65
```

## Critères d’acceptation

- environnement figé et `pip check` vert ;
- tests Python du score, des budgets, de la reproductibilité et du fail-closed verts ;
- tests Laravel/PostgreSQL de RBAC, scope, queue, persistance, abstention, append-only et non-mutation verts ;
- test manuel des budgets 0,5 %, 1 % et 2 % sur un export réel d’au moins 200 lignes ;
- constitution progressive d’étiquettes humaines avant toute mesure de précision locale.

Sans vérité terrain, il est interdit d’annoncer 95 % de précision. Les métriques disponibles sont le volume revu, le taux réalisé, l’accord entre classements et la distribution des décisions humaines.
