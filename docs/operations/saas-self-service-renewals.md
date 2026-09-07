# Lots 5–6 — changement de formule et renouvellement SaaS

## État de cette livraison locale

Reconstruction du lot sur `feature/saas-self-service-renewals-security-qa`, à
partir de `e7bf151`. Le travail non commité de la session précédente n’était plus
présent ; aucune ancienne annonce de test PHP n’est reprise comme preuve.

Le 7 septembre 2026 : 88 tests JavaScript réussis, build Vite réussi (72 modules).
Les tests PHP/PostgreSQL, Pint, la migration et le diagnostic structurel sont
ajoutés/préparés mais **non exécutés localement** : PHP et PostgreSQL sont absents
et l’installation est bloquée par les permissions de l’environnement. Ne pas
activer ce lot en production avant CI verte et recette. Aucun push de ce nouveau
lot, aucune fusion ni relance distante de CI n’a été effectué dans cette reprise.

## Changement de formule

- Seul le propriétaire vérifié de l’entreprise peut demander une autre formule
  active de même devise, après confirmation récente du mot de passe et accord
  explicite sur les conditions affichées.
- La demande crée un abonnement `pending_payment` distinct, avec prix, droits,
  prédécesseur et date d’expiration figés. Un seul changement en attente par
  entreprise. Une répétition de la même demande retrouve la demande existante.
- La facture commence **à la demande**, pas au paiement. La demande expire sous
  24 heures. Il n’y a ni prorata ni remboursement automatique du temps restant
  sur l’ancienne formule ; cette conséquence doit être acceptée dans l’interface.
- Pendant l’attente non expirée, les fonctions de la formule actuelle restent
  disponibles, mais les quotas les plus stricts des deux offres limitent les
  nouvelles créations. Une baisse sous l’utilisation actuelle est refusée ; les
  quotas sont vérifiés de nouveau avant règlement et activation.
- Le règlement exact annule l’ancien abonnement et active le nouveau dans la
  même transaction. Une offre gratuite produit une facture réglée à zéro, sans
  fausse écriture d’encaissement. Un changement non réglé peut être annulé,
  sauf lorsqu’un checkout CMI est encore en cours.
- L’administration peut effectuer un règlement manuel sur la facture du
  changement : entreprise → enregistrer un paiement SaaS. Le changement en
  attente est présenté en priorité ; le montant doit être exact.

## Factures et registre

`saas_invoices` conserve les instantanés de formule, client, émetteur, devise,
montant, période et échéance. Des gardes PostgreSQL interdisent leur altération
et leur suppression. Les liens entreprise/abonnement/facture/paiement sont
contraints ; les contrôles différés exigent la cohérence du règlement au commit.
Les événements sont append-only. Le registre des paiements existant n’est jamais
réécrit ; une correction utilise une contrepassation distincte.

Les factures sont consultables par leur propriétaire ou l’administrateur de
plateforme, sans URL publique de fichier. La page est imprimable en PDF depuis
le navigateur. Il s’agit d’un document de suivi SaaS : **les mentions légales,
l’identification fiscale et le régime de taxe doivent être validés avant une
émission commerciale**. Aucune conformité fiscale n’est revendiquée.

CMI reste une page hébergée avec callback signé et transaction idempotente.
Le retour navigateur GET/POST signé ne confirme jamais un paiement. Les essais
automatisés simulent des signatures ; ils ne démontrent pas une transaction
acceptée par CMI. Le kit marchand et la recette sandbox restent indispensables.

Pour un abonnement déjà doté de factures, le checkout sélectionne la plus
ancienne facture ouverte et n’invente aucune période future. Un règlement
manuel ne peut pas concurrencer un checkout actif ni contourner l’affectation
obligatoire à une facture. Une tentative expirée peut être remplacée.
Une clôture administrative est refusée tant que l’abonnement porte une facture
ouverte ou qu’une tentative liée est encore en cours.

Une contrepassation de renouvellement rouvre la dette et suspend la facturation
jusqu’à régularisation. Une contrepassation de changement annule la facture,
désactive le renouvellement et place la formule sous suspension administrative ;
l’ancienne formule annulée n’est jamais ressuscitée automatiquement. Une saisie
locale ne réalise aucun remboursement bancaire : pour CMI, référence et
confirmation du remboursement effectué dans le portail marchand restent requises.

## Renouvellement avec consentement, sans prélèvement

Les anciens abonnements ont `auto_renew=false`. Le propriétaire peut activer
l’émission des factures pour un abonnement dont la prochaine échéance est
encore future. Une période manquante ou échue doit être régularisée d’abord par
l’administration. Aucun arriéré historique n’est généré pour un compte qui n’a
pas activé cette option.

`saas:process-billing` s’exécute chaque heure, avec verrou distribué du scheduler
et verrou transactionnel par entreprise. À échéance, il émet la facture de la
période suivante. Une seule facture ouverte est traitée à la fois ; une longue
interruption peut nécessiter plusieurs régularisations de périodes successives.
Il n’y a pas de paiement anticipé automatique ni de débit bancaire automatique.

Un renouvellement payant passe en retard, puis en suspension après le délai de
grâce configuré. La fin d’un essai bloque les nouvelles opérations jusqu’au
règlement. Une suspension administrative reste prioritaire : ni paiement ni
scheduler ne peuvent l’effacer. Les entreprises inactives sont exclues des
nouveaux traitements. Désactiver le renouvellement n’annule pas une dette émise.

Le heartbeat `saas-billing` n’est écrit qu’après une passe terminée. Lorsque les
renouvellements sont activés, son absence pendant plus de deux heures est signalée
dans la supervision plateforme. La file de messages reste couverte par les
alertes de backlog, ancienneté et échecs existantes.

## E-mails transactionnels

Chaque événement ajoute au plus un message par propriétaire actif et vérifié.
Le message est placé sur la connexion `saas_billing`, queue `saas-billing`,
table `jobs`, **sur la même connexion PostgreSQL que la facturation, avant commit**.
Un échec d’insertion du job annule la facture et l’événement ; un rollback annule
aussi le job. Un worker distinct ne voit le job qu’après commit. Cette connexion
ne doit pas être redirigée vers une autre base ou vers Redis.

Le worker réessaie cinq fois, avec temporisation. La remise SMTP est
**au moins une fois** : une panne après envoi mais avant acquittement peut
produire un doublon. La livraison unique dans une boîte e-mail n’est pas garantie.
L’e-mail invite à consulter l’état actuel de la facture après authentification.

Cette utilisation s’appuie sur le pilote database et les options transactionnelles
décrits dans la [documentation Laravel 12 des queues](https://laravel.com/docs/12.x/queues).

## Activation et recette

Après sauvegarde vérifiée, CI verte et validation métier :

```dotenv
SAAS_SELF_SERVICE_ENABLED=true
SAAS_RENEWALS_ENABLED=true
SAAS_BILLING_GRACE_DAYS=7
SAAS_INVOICE_ISSUER="Votre raison sociale validée"
```

```bash
php artisan migrate --force
php artisan config:cache
php artisan saas:audit-billing --json
php artisan saas:process-billing
php artisan queue:work saas_billing --queue=saas-billing --tries=5 --timeout=60
```

Le worker doit être supervisé et redémarré après déploiement. Conserver aussi le
worker `default` et le cron existant `schedule:run` chaque minute. Configurer
SMTP et `APP_URL` HTTPS avec le domaine exact ; les hosts non autorisés sont
rejetés en production. Ne jamais ajouter de clé CMI/SMTP au dépôt.

La migration est **forward-only** : son `down()` refuse la destruction des
factures. Elle marque les anciennes tentatives dépassées comme expirées, puis
impose une seule tentative CMI en attente par abonnement. En cas de doublons
encore valides, l’index refuse la migration : rapprocher ces commandes avant de
réessayer, sans supprimer de ligne financière. En cas d’incident, désactiver les flags, conserver la base et appliquer
une migration corrective ; une restauration nécessite une sauvegarde contrôlée
et le rapprochement des éventuels paiements externes.

Recette obligatoire : changement payant/gratuit, baisse refusée, annulation,
expiration, activation CMI sandbox, règlement manuel, rejouement, contrepassation,
renouvellement en retard, suspension administrative, tenant étranger, e-mail réel,
impression PDF et interface mobile. Voir aussi
[l’audit et les tests de charge](../security/saas-load-and-security-audit.md).
