# ADR 0018 — Compléter les parcours SaaS opérationnels

## Contexte

Cette suite de la PR #33 complète les huit axes demandés à partir de `main`
`4b4096bba5de608708f84a03ed2b2671954868dc`. Le monolithe Laravel/Blade,
PostgreSQL, les contraintes de disponibilité et les circuits financiers restent
les fondations. Aucun package, schéma financier ou migration n'est ajouté.

## Parcours

- Les six priorités du dashboard conservent leur résumé de cinq éléments et
  ouvrent désormais une liste complète, paginée par 25, avec le même périmètre
  tenant/agence et les permissions de la ressource. Un groupe inconnu ou non
  autorisé répond 404.
- Le planning filtre aussi par catégorie du tenant et statut opérationnel.
  Les périodes, blocs actifs et règles d'intersection sont conservés.
- Le compte SaaS compare prix et quotas des offres disponibles. La progression
  reflète la vérification du compte, la formule et l'accès effectif ; elle ne
  dépend plus d'un paiement historique et accepte les offres gratuites/essais.
- Le démarrage propose la prochaine étape manquante et les cinq derniers imports
  de l'acteur. Les codes catégorie sont visibles avant l'import. Le rapport CSV
  contient uniquement les lignes invalides de son propre aperçu, neutralise les
  formules et est privé. L'abandon verrouille l'aperçu et efface son contenu ;
  un import confirmé ne peut pas être abandonné.
- Les téléchargements n'ouvrent pas le masque global de chargement. Les listes
  détaillées conservent la pagination et des états vides utiles. Les contrôles
  existants de saisie non enregistrée sont préservés.
- Le portail précise l'agence et l'expiration de session, fournit une page de
  récupération d'accès et détaille les factures imprimables (instantané du client,
  quantités, prix, taxes et soldes). Les données contractuelles ne sont pas réécrites.

## Accès locataire et e-mail

Créer un droit d'accès exige désormais toutes les permissions correspondant aux
informations exposées : `customer.view`, `customer.update`, `customer.identity.view`,
`reservation.view`, `contract.view`, `invoice.view`, `document.download`. La policy sur le client,
l'agence, l'état de l'acteur et la confirmation du mot de passe s'appliquent aussi.
Ces droits sont revalidés pour les sessions et pour l'envoi. Leur retrait invalide
donc aussi les anciens accès. La révocation reste possible avec `customer.update`.

L'agence peut choisir explicitement l'envoi à l'adresse enregistrée sur la fiche.
Aucune adresse du navigateur n'est acceptée. La tâche `SendCustomerPortalLink`
est mise en queue après commit, implémente `ShouldBeEncrypted`, et ne conserve
que l'identifiant d'accès et l'empreinte de l'adresse. Elle recrée le lien signé
lors de son exécution après vérification de l'expiration, de la révocation, de
l'émetteur et de l'adresse actuelle. Un changement d'adresse empêche l'envoi du
lien déjà demandé. Le lien conserve son échéance de 48 heures depuis la création.

Seul un transport SMTP configuré est accepté : jamais `log`, `array` ou un
fallback journalisant les liens. Les erreurs du transport sont remplacées par
un message générique sans exception imbriquée. L'audit ne contient ni adresse,
ni signature, ni corps de message. Les essais utilisent une notification simulée.
Une acceptation par SMTP ne garantit pas la remise en boîte ; la tâche peut être
retentée trois fois et une remise multiple du même lien à usage unique reste possible.

## Export de rentabilité

L'export exige les mêmes permissions `report.view`, `invoice.view` et `expense.view`
que le rapport. Il reprend `ReportCriteria` et `BuildMinimalReport`, toutes
les pages du périmètre (limite 5 000 véhicules), les devises distinctes et les frais
non affectés, sans identité client. Les montants restent des chaînes décimales.
Les résultats sont matérialisés avant la fin du contexte tenant puis streamés en
CSV UTF-8, avec neutralisation tableur et audit de métadonnées uniquement.
La marge est toujours partielle ; le CSV ne constitue pas un résultat comptable.

## Exploitation et validation

Voir `docs/operations/planning-portal-imports-profitability.md` pour la queue et
la mise à jour. Le traitement des e-mails requiert un worker avec une connexion
asynchrone pour éviter d'attendre SMTP dans la requête. Aucune configuration de
production n'est activée automatiquement. La recette réelle SMTP/CMI nécessite
les accès de l'environnement concerné.

`SaasOperationsImprovementsTest` couvre les permissions retirées, la portée des
listes et exports, l'injection tableur, le chiffrement réel du payload de queue,
le destinataire conservé en base, les liens signés, les annulations d'envoi,
la confidentialité des erreurs, l'abandon des imports et l'activation gratuite.
Les gates restent PostgreSQL, la suite JavaScript, Pint, le build et le smoke HTTP.
