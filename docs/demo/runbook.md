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

Le jeu attendu contient deux tenants, trois agences, six rôles métier, seize
véhicules, douze clients/conducteurs, réservations variées, six contrats,
factures et cautions, deux maintenances, une police proche d’échéance et un
sinistre fictif en revue. Vérifier ces ordres de grandeur avec le dashboard et
`rentfleet:doctor`, sans les présenter comme des données réelles.

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
