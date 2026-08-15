# STOP S6 — checklist finale

Cette checklist arrête le développement scientifique avant le rapport et les
slides. Elle a été gelée sur la PR #11 avec une CI entièrement verte, puis sert
de base à la séquence de fusion protégée #9 → #10 → #11 explicitement autorisée
le 15 août 2026.

## Gouvernance du dépôt

- [x] La base `main` auditée avant fusion est
  `31163492fdbfe634546117e1178bfdb6cdfef143`.
- [x] La séquence d'intégration autorisée est strictement #9 → #10 → #11 ; la
  PR #11 porte l'arbre final réconciliant les trois lots.
- [x] Aucun historique n'a été réécrit, aucune branche n'a été forcée, fermée
  ou supprimée.
- [x] HGB, CatBoost et OR-Tools sont les seuls composants candidats finaux ;
  MILP, Scania, MAD et Isolation Forest restent des expériences de rapport.

## HGB D+1 à D+7

- [x] La PR #9 est préservée comme intégration consultative de référence.
- [x] Le protocole, split chronologique, seed, environnement et versions sont
  gelés.
- [x] Le notebook reproductible, la Model Card, la fiche de données, la figure,
  le tableau, les manifests et les SHA-256 sont présents.
- [x] HGB bat les deux baselines gelées sur le test final public.
- [x] La dégradation validation→test et la sous-couverture P05–P95 sont
  documentées.
- [x] Aucune performance locale RentFleet n'est revendiquée.

## CatBoost annulation/no-show

- [x] Le dataset public, sa licence CC BY 4.0 et son SHA-256 sont documentés.
- [x] Le mapping RentFleet, les suppressions de fuite, les cinq blocs
  chronologiques, le déséquilibre, la calibration et SHAP sont reproductibles.
- [x] Le test final intact donne balanced accuracy `0.610399` et macro-F1
  `0.610644`, sous les gates `0.80`.
- [x] Le résultat négatif est gelé dans la PR #10 avec Model Card, fiche de
  données, notebook, figures, tableaux, manifeste et checksums.
- [x] CatBoost n'est ni intégré, ni chargé, ni consommé par le SaaS.

## OR-Tools Min-Cost Flow

- [x] Les scénarios utilisent exclusivement des kilomètres.
- [x] 48/48 solutions sont optimales, réalisables et conformes aux invariants.
- [x] Le taux de service `98,3607 %` dépasse le gate `80 %`.
- [x] Le coût de décision et la demande non servie sont strictement meilleurs
  que `no_relocation` et `greedy`.
- [x] Chaque appel solveur est contrôlé sous 5 secondes ; la valeur observée
  reste dépendante de la machine.
- [x] Notebook, Model Card, fiche synthétique, figures, tableaux, manifests,
  versions et checksums sont présents.
- [x] L'intégration est consultative, tenant-scopée, privée, idempotente et sans
  écriture opérationnelle.

## Chaîne bout en bout et J15-B

- [x] La démonstration synthétique relie prévision HGB → abstention CatBoost →
  demande effective → OR-Tools → décision humaine append-only.
- [x] Les données sont explicitement marquées synthétiques et ne contiennent ni
  secret, identité directe, coordonnée réelle, tenant ou agence réelle.
- [x] Une décision humaine conserve toujours l'effet
  `NO_OPERATIONAL_ACTION`.
- [x] L'index transversal `J15B_INDEX.md` relie les trois décisions, leurs PR,
  révisions immuables, notebooks et preuves.
- [x] `s6-evidence-manifest.json` est contrôlé automatiquement et
  `S6_SHA256SUMS` protège les octets de clôture.
- [x] Les preuves PostgreSQL, Pint, audits, build et tests scientifiques sont
  exigées par la CI de la PR #11.
- [x] La CI verte du head de cette checklist doit être consignée dans la
  conversation de la PR #11 avant l'annonce STOP S6.

## Limites après STOP S6

- Pas d'historique RentFleet réel suffisant : statut local non validé.
- Le benchmark HGB est un proxy de mobilité partagée à Munich.
- Le benchmark CatBoost vient de l'hôtellerie et son résultat est négatif.
- Les scénarios, coûts et demandes OR-Tools sont synthétiques.
- La fusion n'est pas une décision scientifique : la revue humaine et la
  séquence protégée #9 → #10 → #11 restent une phase d'intégration séparée.
- Aucun rapport ni slide n'est commencé dans ce jalon.
