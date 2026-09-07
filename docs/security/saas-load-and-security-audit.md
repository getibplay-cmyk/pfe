# Lot 7 — audit ciblé et test de charge borné

## Validation locale au 7 septembre 2026

| Contrôle | Résultat |
| --- | --- |
| Suite JavaScript | 88 tests réussis, dont 8 tests du pilote de charge |
| Build Vite | Réussi, 72 modules |
| PHP, Pint, PostgreSQL et migration | Non exécutés : runtime absent, installation refusée |
| Charge sur le SaaS Laravel | Préparée dans la CI, non mesurée localement |
| CMI sandbox / e-mail SMTP réel | Non validés |

Les tests du pilote HTTP utilisent un serveur local factice : ils vérifient le
nombre de requêtes, la concurrence, les limites, le refus des cibles externes,
les délais et les redirections. Ils ne prouvent pas les performances du SaaS.

## Protections ajoutées et limites

- Confirmation récente du mot de passe pour checkout, changement de formule,
  renouvellement et règlements/contrepassations administratifs.
- Buckets de limitation distincts pour les opérations sensibles, afin qu’un
  renvoi de vérification ne partage pas son compteur avec le callback CMI.
- Validation stricte du domaine `APP_URL`, sans autorisation implicite des
  sous-domaines. Ce paramétrage suit la
  [documentation Laravel des hosts autorisés](https://laravel.com/docs/12.x/requests#configuring-trusted-hosts).
- En-têtes défensifs placés avant la maintenance ; réponses privées et erreurs
  privées non mises en cache. Referrer désactivé pour liens d’invitation et de
  récupération. Aucun CSP prétendument complet : il faut encore qualifier les
  usages Alpine, styles et scripts avant de l’imposer.
- Verrous par entreprise communs aux actions de facturation ; montant décimal
  calculé côté serveur, affectation exacte à la facture, journal append-only.
- Callback : signature, expiration fermée à la borne exacte, rejeu d’une
  signature invalide toujours refusé, contrôle de la transaction sur un rejeu
  payé, conservation d’un identifiant signé valide sur un refus local pour le
  rapprochement. Un incident interne produit une réponse de refus sans page HTML
  de débogage. Aucun payload bancaire enregistré.
- Une tentative terminale expirée reste immuable : un avis bancaire tardif exige
  un rapprochement dans le portail marchand ; il ne réactive pas l’accès.
- Suspensions administratives prioritaires sur la facturation. Aucun effacement
  de données métier, ancien abonnement jamais réactivé implicitement.

`php artisan saas:audit-billing --json` vérifie en lecture seule les sept triggers
du lot (table, schéma, fonction, corps comparé à la migration, activation, timing,
différabilité), quatre index (unicité, validité, colonnes, prédicat) et trois
contraintes critiques. Les tests négatifs altèrent temporairement un trigger,
un index et un CHECK dans une transaction qui est annulée. Ce contrôle est
ciblé : il ne remplace ni une revue de l’ensemble du schéma et des droits SQL,
ni un pentest indépendant, ni une certification de sécurité.

## Exécuter le smoke test

Uniquement sur une instance locale et une base de test autorisée, sans secret
de production ni passerelle de paiement activée :

```bash
npm run test:js
php artisan test --filter=SaasSelfServiceBillingTest
php artisan test --filter=SecurityHeadersTest
php artisan saas:audit-billing --json
php artisan serve --host=127.0.0.1 --port=8000
```

Dans un second terminal :

```bash
npm run qa:load-smoke -- --base-url http://127.0.0.1:8000 --requests 60 --concurrency 4 --p95-ms 2000
```

Le pilote n’autorise que HTTP sur une adresse loopback numérique et des GET sur
`/health`, `/login`, `/tarifs` ou `/`. Il refuse credentials, paramètres d’URL,
cibles externes et routes d’écriture. Il ne suit pas les redirections et ne
transmet aucun cookie. Limites dures : 200 requêtes, 8 simultanées, 5 secondes
par requête, 120 secondes au total, 1 Mio par réponse. Tout statut non 200,
timeout, budget incomplet ou p95 excessif fait échouer le processus. Les résultats
ne contiennent aucun corps de réponse ni donnée client.

La CI utilise un seuil p95 de 5 secondes pour un smoke de disponibilité sur le
runner, **pas un objectif de service**. Un vrai test de capacité exige un
environnement représentatif, des données synthétiques, des scénarios authentifiés
multi-tenant, une concurrence progressive, des mesures CPU/SQL/queue et des
critères de saturation définis avec l’exploitant. Il reste à réaliser avant de
publier une capacité chiffrée ou une promesse de performance.
