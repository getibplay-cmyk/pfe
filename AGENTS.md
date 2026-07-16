# Instructions RentFleet

Lire `RentFleet_Cahier_Architecture_Executable_Codex.md` avant toute
modification architecturale ou tout nouveau module.

- Laravel monolithique modulaire avec PostgreSQL ; ne pas utiliser SQLite pour la suite principale.
- Inspecter Git et préserver les changements existants.
- Développer par vertical slice avec migrations, autorisations et tests dans le même lot.
- Toute donnée métier est isolée par tenant côté serveur.
- `tenant_id` est dérivé du `TenantContext` et n’est jamais accepté depuis le client.
- Le global scope complète les policies et les contraintes ; il ne les remplace pas.
- Une route métier sans tenant doit échouer explicitement.
- Les platform admins utilisent uniquement les routes `/platform/*` dédiées.
- Les agency managers restent limités à leur agence.
- Ne jamais auditer mots de passe, tokens, secrets ou documents personnels complets.
- Chiffrer les numéros d’identité et de permis ; stocker une empreinte de recherche tenant-scopée séparée.
- Ne jamais exposer une identité complète dans une liste, une URL, un audit ou un log applicatif.
- Stocker les documents sur le disque privé uniquement, avec chemin serveur aléatoire et téléchargement contrôlé.
- Ne jamais utiliser `Storage::url` pour un document privé ni accepter un chemin ou un type polymorphe du client.
- Vérifier MIME, extension, taille et autorisation avant chaque ajout ou téléchargement de fichier.
- `vehicle_blocks` est l’unique source de vérité de disponibilité ; ne jamais ajouter de booléen de disponibilité sur `vehicles`.
- Toute période de réservation ou de bloc utilise l’intervalle semi-ouvert `[début, fin)` et des timestamps avec timezone.
- Ne jamais retirer ni contourner la contrainte GiST `vehicle_blocks_no_active_overlap_excl` ; une vérification PHP seule est insuffisante.
- Confirmation, annulation et expiration de réservation passent par leurs actions transactionnelles et créent un historique.
- Un tarif confirmé est figé dans `pricing_snapshot` et ne doit jamais être recalculé silencieusement.
- Les montants sont des décimaux `numeric(14,2)` manipulés sans `float`.
- Ne pas ajouter de package, SPA ou microservice sans accord.
- Un contrat provient uniquement d’une réservation `confirmed`, puis reprend son `vehicle_block` existant sans créer de bloc concurrent.
- Une version contractuelle acceptée et une inspection `completed` sont immuables ; toute correction contractuelle crée une nouvelle version traçable.
- Aucune règle automatique ne décide de la responsabilité d’un dommage. Toute responsabilité et tout frais associé exigent une décision humaine explicite.
- Le lot 04 s’arrête à `returned`. Le statut `closed`, les paiements et les mouvements de caution appartiennent au lot financier suivant.
- Une facture émise et ses lignes sont immuables ; paiements, allocations et cautions se corrigent uniquement par contrepassation append-only.
- `closed` exige une facture payée, aucune écriture en attente et une caution soldée ; les résumés du contrat ne remplacent jamais les registres.
- Une maintenance approuvée utilise obligatoirement `vehicle_blocks` et sa contrainte GiST ; le retour du véhicule à `active` reste une confirmation humaine.
- Chiffrer et masquer les numéros de police et références assureur. RentFleet ne décide jamais de la responsabilité juridique d’un sinistre.
- Le périmètre financier exclut comptabilité générale, grand livre, carte bancaire, passerelle de paiement et calcul fiscal officiel.
- Ne jamais commiter `.env`, `.env.testing`, secrets ou données personnelles.
- Exécuter les tests PostgreSQL pertinents, Pint et le build avant de terminer.
- Rapporter fichiers modifiés, commandes, résultats et limites.

## Lot 06 — release et exploitation

- PostgreSQL est l’unique moteur pris en charge ; aucune configuration SQLite
  ou `:memory:` ne doit être réintroduite.
- Une commande destructive est interdite sur `rentfleet`. Les restaurations
  automatisées ciblent uniquement `rentfleet_restore_test`.
- Les sauvegardes associent dump PostgreSQL, stockage privé, manifeste et
  empreintes SHA-256 ; leurs sorties restent ignorées par Git.
- Les scripts ne lisent ni n’affichent de mot de passe. Utiliser
  `pgpass`/`PGPASSFILE` et des variables d’environnement non sensibles.
- HSTS est réservé à la production servie en HTTPS. Ne pas activer une CSP
  stricte sans inventaire et tests Livewire/Vite.
- Les erreurs de production restent génériques ; les logs utilisent un UUID de
  corrélation et excluent secrets, identités, cartes et contenu documentaire.
- Aucun module IA, table décisionnelle ou changement métier n’appartient au lot
  de release candidate.

## Lot 06D — administration SaaS et reporting

- Le provisioning d’un tenant est une transaction unique : tenant, agence
  initiale, Tenant Owner et paramètres sont créés ensemble ou pas du tout.
- Un mot de passe initial est aléatoire, montré une seule fois, jamais journalisé
  et impose son remplacement avant toute route métier.
- Une suspension invalide les sessions et bloque connexion, contexte tenant et
  traitements applicatifs ; elle ne supprime aucune donnée.
- Les tenants, agences et utilisateurs sont désactivés ou suspendus, jamais
  supprimés physiquement depuis les interfaces d’administration.
- Toute agence, tout rôle et tout tenant soumis par le navigateur sont revalidés
  dans le périmètre serveur de l’acteur.
- Les exports CSV sont streamés, limités au tenant/agence autorisé, sans donnée
  d’identité sensible et neutralisent les formules tableur.
- Les KPI financiers utilisent les colonnes `numeric` et `DecimalMoney`, jamais
  des conversions `float`. Leur définition publique est tenue dans
  `docs/reporting/kpi-definitions.md`.
