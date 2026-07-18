# Démonstration hors ligne — 10 à 12 minutes

Toutes les personnes, entreprises, plaques et références utilisées sont
fictives. Préparer une base dédiée de démonstration ; ne jamais réinitialiser
`rentfleet` pour cette séquence.

## Préparation avant la soutenance

```powershell
# Dans .env.testing, DB_DATABASE doit rester rentfleet_test.
php artisan migrate:fresh --seed --env=testing --no-interaction
php artisan rentfleet:doctor --env=testing
npm run build
```

Pour une démonstration web persistante, créer séparément `rentfleet_demo`,
copier la configuration vers un fichier local non versionné, vérifier le nom
avec `php artisan db:show`, puis seulement exécuter `migrate:fresh --seed` sur
cette base dédiée. Aucun raccourci destructif n’est fourni par le dépôt.
Définir localement `DEMO_PASSWORD` avant le seeding ; aucun mot de passe fixe
n’est versionné. Les seeders refusent l’environnement `production`.

## Comptes et données attendues

| Usage | Compte fictif |
|---|---|
| Tenant Owner principal | `tenant-owner@atlas-demo.test` |
| Agent de location | `rental-agent@atlas-demo.test` |
| Gestionnaire de flotte | `fleet-manager@atlas-demo.test` |
| Second tenant | `owner@rif-demo.test` |
| Administration plateforme | `platform@rentfleet.test` |

Le jeu attendu contient deux tenants, trois agences, six rôles métier, au moins
seize véhicules, douze clients/conducteurs, réservations variées, huit contrats,
factures et cautions, deux maintenances, une police proche d’échéance et un
sinistre fictif en revue. Vérifier ces ordres de grandeur avec le dashboard et
`rentfleet:doctor`, sans les présenter comme des données réelles.

## Parcours navigateur Lot 06C

Ce parcours nominal ne nécessite ni Tinker, ni SQL manuel, ni appel direct à
une API. Se connecter avec `tenant-owner@atlas-demo.test` et la valeur locale
de `DEMO_PASSWORD`, puis utiliser exclusivement les écrans suivants :

1. Ouvrir **Contrats**, filtrer sur `ready`, puis ouvrir le contrat préparé.
2. Dans **Prérequis du cycle**, vérifier l’identité et le permis, téléverser un
   PDF fictif dans **Version courante**, puis enregistrer l’acceptation.
3. Dans **Finance du contrat**, recevoir la caution demandée avec la devise
   affichée. Réaliser ensuite l’inspection de départ et activer le contrat.
4. Réaliser l’inspection de retour, ajouter au besoin un dommage, calculer les
   frais, les revoir humainement puis finaliser le retour.
5. Depuis la même fiche, créer la facture. Sur la fiche facture, l’émettre,
   enregistrer un paiement manuel, l’allouer puis le comptabiliser.
6. Revenir au contrat, rembourser ou retenir explicitement le solde de caution,
   puis utiliser **Vérifier et clôturer**. Les prérequis manquants restent
   affichés en français et empêchent la transition.
7. Ouvrir **Maintenance** : créer un ordre, l’approuver, le démarrer et le
   terminer. Vérifier le bloc véhicule, l’historique et la dépense générée.
8. Ouvrir **Assurance** : créer une police et une garantie, déclarer un
   sinistre sans choisir de statut avancé, puis suivre uniquement les
   transitions proposées jusqu’à la clôture.

## Parcours administration Lot 06D

Ce parcours utilise un compte plateforme préparé localement. Il ne faut jamais
présenter ni enregistrer son mot de passe dans une capture ou dans le dépôt.

1. Ouvrir `/platform/dashboard`, relever les volumes globaux et les alertes de
   tenants suspendus ou sans agence active.
2. Créer un tenant depuis `/platform/tenants/create`. Copier hors écran le mot
   de passe temporaire affiché une seule fois ; vérifier qu’un rafraîchissement
   ne permet pas de le retrouver.
3. Se connecter avec le Tenant Owner créé. Le changement du mot de passe initial
   doit être imposé avant le dashboard.
4. Dans **Organisation**, vérifier les paramètres du tenant, créer une seconde
   agence et un agent borné à cette agence.
5. Dans **Réservations**, exporter une période en CSV, puis ouvrir **Rapports**
   et rappeler les définitions affichées sous les indicateurs.
6. Revenir avec le compte plateforme, suspendre le tenant avec un motif et
   vérifier que la session tenant et une nouvelle connexion sont refusées.
7. Réactiver le tenant et confirmer que ses données sont intactes.

La démonstration ne désactive pas une agence portant un cycle de location actif
et ne supprime physiquement ni tenant, ni agence, ni utilisateur.

## Parcours reporting Lot 06F-D1

1. Avec un Tenant Owner, ouvrir **Rapports** et choisir une période contenant
   les scénarios de démonstration. Montrer le résumé exact des filtres et la fin
   exclusive affichée avec son fuseau.
2. Choisir une agence, puis tenter la même agence avec un Agency Manager : la
   liste ne propose que son périmètre et une agence forgée est refusée côté
   serveur.
3. Expliquer le taux d’utilisation : secondes de blocs actifs intersectées avec
   les intervalles de capacité véhicule, divisées par cette capacité. Montrer
   la ventilation réservation, contrat, manuel et maintenance.
4. Dans **Finance par devise**, rappeler qu’aucune ligne MAD/EUR n’est
   additionnée. Les paiements en attente sont exclus et une contrepassation
   diminue l’encaissement net.
5. Parcourir la table de réservations paginée, sans identité ni document privé,
   puis télécharger le résumé CSV. Vérifier le BOM UTF-8, le séparateur `;` et
   l’entrée `report.exported` dans l’audit, sans contenu exporté.
6. Terminer par `php artisan rentfleet:doctor` : périodes, allocations, blocs et
   12 index de reporting doivent être au vert.

## Parcours UX et rôles Lot 06E

La navigation desktop et le menu mobile présentent exactement les mêmes modules
autorisés. Pour une démonstration fluide, changer de compte dans cet ordre :

1. **Platform Admin** : dashboard plateforme, recherche d’un tenant et fiche de
   suspension/réactivation. Aucun module métier tenant n’est visible.
2. **Tenant Owner** : dashboard global, entreprise, agences, utilisateurs,
   audit, rapports et tous les modules opérationnels. Ouvrir le profil pour
   montrer rôle, tenant et agence en lecture seule.
3. **Agency Manager** : dashboard et ressources limités à son agence. Montrer
   qu’une seconde agence et ses acteurs ne figurent ni dans les listes, ni dans
   l’activité récente.
4. **Rental Agent** : clients, disponibilité, réservation et contrat. La finance
   est consultable, mais les écritures sensibles restent absentes.
5. **Fleet Manager** : véhicules, catégories, maintenance et assurance. Les
   actions financières ne sont pas proposées.
6. **Accountant** : finance, contrats, réservations, tarifs et rapports. Montrer
   émission, allocation ou contrepassation uniquement sur un scénario préparé.
7. **Viewer/Auditor** : listes, rapports et audit en lecture seule. Aucun bouton
   de création, modification, approbation ou contrepassation n’est affiché.

Sur mobile, ouvrir le bouton **Ouvrir le menu**, parcourir les mêmes groupes,
puis fermer par le bouton dédié ou la touche Échap. Les refus directs 403 sont
prouvés par les tests et ne sont pas provoqués sur la base de démonstration.

Les documents sont toujours ouverts via leur fiche et leur contrôleur de
téléchargement autorisé. Aucune URL `storage/*` ne fait partie du scénario.

## Preuve d’exploitation Lot 06F-D2

Préparer `pgpass.conf`, `rentfleet_restore_test`, un volume de sauvegarde
chiffré et une racine documentaire temporaire avant la soutenance. Ne jamais
afficher le contenu de pgpass, `.env`, `APP_KEY` ou un document privé.

1. Exécuter avec PHP Herd 8.5.8 `schedule:list`, puis
   `operations:scheduler-heartbeat` et `rentfleet:doctor`. Montrer uniquement
   les fréquences, le fuseau et l’état récent du heartbeat.
2. Présenter les refus automatisés : base de sauvegarde non autorisée, cible de
   restauration vide, `rentfleet`, `rentfleet_test` et variante du nom dédié.
3. Lancer une sauvegarde réelle de `rentfleet` vers le volume externe. Montrer
   le manifeste sans ouvrir les documents : statut, tailles, nombre de fichiers
   et présence des SHA-256.
4. Restaurer exclusivement dans `rentfleet_restore_test` et une racine privée
   temporaire hors dépôt avec `-ConfirmRestore`.
5. Exécuter `verify-restore.ps1`. Montrer les comptages agrégés identiques,
   contraintes/triggers/index et doctor vert, sans valeur chiffrée affichée.
6. Confirmer que `rentfleet` possède toujours les mêmes comptages et que la
   racine `storage/app/private` n’a pas été remplacée.
7. Noter durées et tailles dans la preuve D2. Une étape non exécutée doit être
   annoncée comme bloquée, jamais comme réussie.

Les commandes exactes sont dans `docs/operations/backup-and-restore.md`. Après
la démonstration, annoncer le chemin documentaire temporaire exact avant son
nettoyage ; ne jamais supprimer un chemin calculé vide ou large.

## Script oral

1. **0:00–0:45 — Santé et connexion.** Montrer `/health`, puis se connecter
   avec le compte fictif Tenant Owner préparé localement.
2. **0:45–1:30 — Isolation.** Présenter le tenant Atlas, ses agences et rappeler
   que le tenant est dérivé du compte, jamais d’un champ client.
3. **1:30–2:15 — Flotte et documents.** Ouvrir un véhicule et un client ;
   montrer les identifiants masqués et le téléchargement privé contrôlé.
4. **2:15–3:15 — Réservation.** Rechercher une disponibilité, ouvrir une
   réservation confirmée et montrer le tarif figé ainsi que son historique.
5. **3:15–4:00 — Concurrence.** Expliquer la contrainte GiST et présenter le
   test qui refuse un second bloc chevauchant sans dépendre de l’interface.
6. **4:00–5:15 — Contrat.** Convertir ou ouvrir le contrat préparé, présenter
   version SHA-256, acceptation, inspection de départ et statut actif.
7. **5:15–6:30 — Retour.** Montrer l’inspection retour, les écarts, un dommage
   en revue humaine et les frais calculés sans `float`.
8. **6:30–7:45 — Finance.** Montrer facture, allocation, paiement et registre de
   caution ; rappeler que toute correction est une contrepassation.
9. **7:45–8:30 — Clôture.** Montrer le contrat `closed`, son historique et les
   conditions PostgreSQL de solde/caution.
10. **8:30–9:15 — Maintenance et assurance.** Montrer un bloc maintenance et
    une police proche d’échéance avec numéro masqué.
11. **9:15–10:15 — Audit et second tenant.** Montrer l’audit corrélé, puis un
    accès du second tenant qui ne voit aucune ressource Atlas.
12. **10:15–11:30 — Exploitation.** Exécuter `rentfleet:doctor`, présenter les
    scripts de sauvegarde/restauration et la preuve de restauration dédiée.

Phrases courtes à conserver : « Le tenant vient du compte authentifié », « La
contrainte PostgreSQL reste l’arbitre de la disponibilité », « Une correction
financière ajoute une écriture, elle ne réécrit pas l’historique » et « L’IA du
lot 07 assistera la revue humaine sans bloquer ce SaaS fonctionnel ».

Captures à préparer : dashboard Atlas, refus de chevauchement, version de
contrat et empreinte, inspection retour/dommage, facture payée, contrat fermé,
audit corrélé, refus cross-tenant et sortie de `rentfleet:doctor`.

## Plan de secours

Conserver avant la soutenance : sortie texte de `rentfleet:doctor`, résultat de
tests, captures des pages clés et archive de preuve. La démonstration ne dépend
d’aucun accès Internet, service mail ou passerelle de paiement.

Limites à annoncer honnêtement : pas de paiement réel, signature qualifiée,
comptabilité générale, décision juridique automatisée, cloud obligatoire ou
modèle ML dans cette release candidate.
