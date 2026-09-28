# Vérification du lot du 13 septembre 2026

**Historique du lot initial `061467e`.** Les résultats ci-dessous sont conservés
pour traçabilité. La continuation SANAD PILOT, ses nouveaux contrôles et ses résultats
actualisés figurent dans [continuation-sanad-pilot.md](continuation-sanad-pilot.md)
et `local-verification-2026-09-13.json` ; ils ne sont pas remplacés par ceux de ce
premier passage.

## Résultats locaux effectivement obtenus

| Vérification | Résultat | Limite |
|---|---|---|
| PHPUnit ciblé, PHP 8.5.10 | 10 tests, 1 062 assertions, aucun échec | Tests purs : catalogue FR/AR, vrai sous-processus sans secret hérité, audits bornés, protocole INSTREAM synthétique |
| JavaScript | 103 tests réussis | Logique des composants ; pas un audit visuel navigateur |
| Syntaxe PHP | 74 fichiers contrôlés, aucune erreur | N’exécute pas les parcours métier |
| Pint | Réussi | Style PHP |
| Build Vite 6.4.3 | Réussi, 80 modules | Bundle compilé ; ne prouve pas la compatibilité CSP stricte |
| `artisan view:cache` | Réussi | Compilation Blade, sans qualification visuelle |
| Routes du profil | 11 routes listées | Enregistrement des routes, pas tests d’autorisation |
| `rentfleet:security-audit --json` | 8 contrôles locaux réussis | Profil local ; aucun résultat de production ni rôle PostgreSQL revendiqué |
| Registre et empreintes des pièces jointes | 4 sources, 332 sections, 236 contrôles, 50 scénarios | Traçabilité complète du registre, pas conformité complète du SaaS |
| Générateur de SBOM | 396 composants de lockfiles inventoriés | Essai de génération ; ni graphe complet installé ni inventaire d’images/modèles |
| YAML et scripts de sécurité | YAML sans clé dupliquée, actions épinglées, Python compilable | Semgrep/Gitleaks non exécutés |
| `git diff --check` | Réussi | Vérification du diff local |

Les tests du client ClamAV utilisent un serveur local synthétique : ils vérifient
le flux exact et les réponses sain/suspect/erreur/tronquée. **Ils ne constituent pas
une qualification antivirus avec signatures réelles.** Aucun malware réel n’a été
utilisé et aucun document client n’a été envoyé à un service de scan externe.

## Commandes reproductibles

```powershell
php vendor/bin/phpunit tests/Unit/LocalizationCatalogTest.php tests/Unit/RestrictedProcessEnvironmentTest.php tests/Unit/AuditBoundsTest.php tests/Unit/ClamAvProtocolTest.php
npm.cmd run test:js
npm.cmd run build
php vendor/bin/pint --test
php artisan view:cache
php artisan rentfleet:security-audit --json
python scripts/security/check_control_register.py
```

Le test protocolaire local utilise `pcntl` ; il est explicitement ignoré si cette
extension n’existe pas, notamment sous Windows. Les autres tests ne dépendent pas
d’un antivirus externe.

## Tests préparés nécessitant PostgreSQL

- `SecurityHardeningAccountsTest` : changement d’e-mail en deux temps, rejeu,
  reset, révocation, expiration absolue, mots de passe, contrôle de compromission,
  limites distribuées et proxies ; un mauvais token ne déclenche pas HIBP.
- `SecurityHardeningFilesTest` : scanner indisponible, mutation d’octets, absence
  de publication, réencodage d’image et livraison refusée après altération.
- `SecurityHardeningHttpTest` : JSON invalide/profond/volumineux, nonces renouvelés
  et configuration de production insuffisante.
- `SecurityHardeningCapacityTest` : budget tenant et budget global cumulés.
- `SecurityHardeningWorkerTest` : contexte/acteur effacés après échec, isolation
  du job suivant, contexte HTTP préservé lors d’un job synchrone.
- Régression : comptes, rôles, agence/tenant, documents, portail, réservations,
  contrats, finance, abonnement CMI, jobs et entraînement.

```powershell
# Seulement avec la vraie base PostgreSQL autorisée par TestDatabaseGuard.
php artisan test --filter=SecurityHardening
php artisan test
```

Aucun environnement PostgreSQL de test validé n’est disponible ici. Le garde-fou
`TestDatabaseGuard` n’a pas été retiré ou contourné. Aucun test métier du nouveau
lot n’est annoncé comme réussi. Les résultats de la précédente PR 37 (827 tests
PHP) concernent sa version et ne sont pas transférés à ces changements.

## Publication et risque résiduel

Base distante vérifiée : `9321b2565242fa49858f467b871769e7b5c19c59`, dépôt public
`getibplay-cmyk/pfe`. La branche distante `feature/security-reference-hardening`
existe mais pointe encore sur cette base. La revue automatique a rejeté la
publication du code parce qu’elle exige l’autorisation explicite de divulguer ce
lot précis sur un dépôt public. Une tentative a aussi été annulée par l’utilisateur.
Aucun commit de durcissement n’est publié, aucune PR nouvelle n’est ouverte, aucune
CI de ce lot n’est exécutée et aucune fusion n’est faite.

Après autorisation de publication, commencer par une PR de travail, exécuter la
CI et corriger les résultats avant toute fusion. Les nouveaux outils restent
confinés au runner CI jetable ; aucun package applicatif n’a été installé.

Le registre contient actuellement **160 PARTIEL, 64 NON_VERIFIE et 12
NON_APPLICABLE_JUSTIFIE**. Chaque entrée conserve sa prochaine vérification.
Les critères de fin comprennent encore notamment les tests PostgreSQL, la
qualification du scanner/SMTP, CSP scripts bloquante compatible, MFA résistant au
phishing et step-up administratif, rôle DB réel, séparation système IA/parseurs,
preuves DNS/TLS/pare-feu, alertes, rétention/contrats et restauration mesurée.
Le lot ne vaut pas feu vert de production.
