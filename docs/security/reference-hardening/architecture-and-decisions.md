# Architecture, menaces et arbitrages

## Périmètre effectif

RentFleet/Sanad Pilot reste un monolithe Laravel/PostgreSQL. Le navigateur utilise
Blade, Tailwind, Alpine et les composants Livewire existants. Une session serveur,
les droits, `TenantContext`, les policies et des contraintes PostgreSQL composées
protègent les données métier. Les administrateurs de plateforme utilisent leurs
routes dédiées. Le portail locataire est un périmètre distinct de celui du personnel.
Les objets privés, datasets et résultats ne deviennent pas publics par leur UUID.

| Actifs | Frontière de confiance | Défense et risque à vérifier |
|---|---|---|
| Identité, session, MFA, récupération | Navigateur vers Laravel | Limitation compte/source, revalidation, preuves liées à l’utilisateur/version ; risque de phishing TOTP résiduel |
| Flotte, contrat, finance | Laravel vers PostgreSQL | Scope + policy + FK/contraintes + transactions ; vrai rôle non propriétaire à qualifier |
| Photos et documents | Upload vers scanner/parseur puis disque privé | Bornes, type réel, verdict, réencodage, hash exact ; isolation OS du parseur à qualifier |
| Paiement SaaS | CMI vers callback Laravel | Signature, corrélation montant/devise/marchand, idempotence ; sandbox du prestataire à rejouer |
| Données IA | Export autorisé vers entraînement externe | Sélection minimale, traçabilité, JSON strict au retour, promotion humaine ; transferts/droits à documenter |
| Exécution IA | Worker vers Python | Environnement fermé et limites de temps ; cela ne constitue pas un sandbox système |
| Livraison du code | PR vers CI vers artefact | Identités minimales, scans, empreinte/provenance ; règles de branche et promotion externes à vérifier |
| Sauvegarde et logs | Production vers copie indépendante | Séparation d’identités et rétention ; restauration et réception d’alerte à prouver |

## Scénarios STRIDE retenus

| Menace | Scénario concret | Contrôles |
|---|---|---|
| Usurpation | Attaquant répartit les essais sur plusieurs IP pour un même compte | IAM-03/04, SES-03, limites compte et source |
| Usurpation | Nouvelle adresse remplace l’identité avant vérification | IAM-06, jeton court, GET non mutant, POST signé + CSRF |
| Altération | Document remplacé entre scanner et téléchargement | FIL-05/08, version/hash, livraison de la copie inspectée |
| Répudiation | Suppression d’une trace après changement de rôle ou paiement | LOG-01/03/07, audit transactionnel et copie extérieure |
| Divulgation | Objet, suggestion, cache ou export d’une autre entreprise | TEN-01 à 10, policies et recontrôles des tâches |
| Divulgation | Nouvelle clé fournisseur héritée par Python | AI-02, FIL-04, environnement fermé ; restriction OS complémentaire |
| Déni de service | Photos volumineuses, JSON profond, jobs IA accumulés | APP-08, FIL-02, DOS-02/03/04, limites d’admission et quotas OS |
| Élévation | Proxies falsifiés ou propriété client `tenant_id`/rôle | NET-04, TEN-03, types/schémas serveur |
| Altération | Callback CMI dupliqué ou retour navigateur falsifié | EXT-01 à 05, BUS-04/05, état serveur de référence |
| Altération | Modèle inconnu ou dataset empoisonné promu directement | AI-08/10/11, manifeste, évaluation et approbation humaine |
| Perte totale | Compte web compromis détruit production et copies | BAK-02/03/06/09, identités/clefs séparées et exercice de perte totale |
| Chaîne compromise | PR exécute des sources non fiables avec identité de déploiement | CICD-02 à 07, pas de secrets sur PR ni checkout privilégié |

## Décisions sur les divergences des documents

| ID | Prescription rencontrée | Décision pour ce projet |
|---|---|---|
| D-01 | Top 10 « 2026 », ASVS 4.0.3 L3 ou ASVS 5.0 | Référencer les éditions publiées ; ASVS 5.0.0 et Top 10 applicatif 2025. Les IDs locaux du registre ne sont pas des IDs ASVS officiels. Aucune certification L3 revendiquée. |
| D-02 | Composition complexe, rotation périodique, minimum variable | Phrases uniques ≥15 caractères, liste de refus et contrôle de compromission en production. Pas de composition imposée ni rotation arbitraire. Avec bcrypt, refus au-delà de 72 **octets**, sans troncature ; la prise en charge complète de 64 caractères Unicode exige une migration de hasher qualifiée. |
| D-03 | Argon2id obligatoire partout | Conserver la compatibilité des comptes bcrypt et coût ≥12 en production ; prévoir migration graduelle des hashers avec tests de connexion/récupération. Ne pas changer le driver en bloquant les comptes existants. |
| D-04 | Cookie `__Host-` avec Domain | Exemple corrigé : Secure, Path=/, aucun Domain. Le diagnostic refuse une combinaison incohérente. |
| D-05 | TLS 1.3 exclusivement, mTLS/SPIFFE partout | TLS 1.2/1.3 correctement configuré selon clients et hébergement, validation jusqu’à l’origine. Identités de workload/mTLS selon frontières réelles. Pas de PKI distribuée artificielle pour ce monolithe. |
| D-06 | RLS ou base par tenant obligatoires | Architecture actuelle : scopes, policies, revalidation et contraintes tenant. Ne pas annoncer RLS. Toute évolution RLS exige inventaire, rôle non propriétaire, FORCE RLS si nécessaire, tests de pooling et chemins plateforme/migration. |
| D-07 | CSP stricte immédiate | Base/object/frame bloqués dès ce lot ; scripts/styles en Report-Only avec nonce. Alpine standard nécessite encore évaluation dynamique : inventaire et migration compatible avant blocage. Aucun `unsafe-eval` présenté comme CSP stricte. |
| D-08 | JWT asymétrique, OIDC/SAML obligatoires | Aucun JWT, OAuth/OIDC/SAML applicatif présent. Pas d’ajout pour satisfaire une prescription sans surface correspondante. Requalifier l’intégralité des flux si fédération ajoutée. |
| D-09 | Kubernetes, service mesh, eBPF, RASP, blockchain des logs | Mécanismes optionnels selon déploiement. La non-présence est justifiée ; durcissement système, logs indépendants et isolation restent applicables. |
| D-10 | RAG, agents/MCP, prompt injection | SaaS actuel : vision et modèles tabulaires consultatifs, sans RAG/agent doté d’outils. Les risques datasets, poids, JSON, exécution et approbation restent applicables. |
| D-11 | Tous les outils du catalogue doivent être installés | Composer/npm, règles PHP/JS/Python et Gitleaks exécutés localement avec des outils d’analyse isolés ; nouvelle CI à exécuter. Aucune nouvelle dépendance du SaaS. Limites documentées : huit règles ciblées ne couvrent pas tout SAST ; scan des sources et scan complet de l’historique sont deux preuves distinctes. |
| D-12 | Un backup ou un score de scanner prouve la sécurité | Exiger preuve de restauration, vrais rôles DB, tests négatifs, alertes reçues et revue indépendante. Les inconnues restent dans le registre et son dénominateur. |
| D-13 | Obligation réglementaire unique, notification automatique en 72 h | Déterminer régimes/contrats réellement applicables avec responsable compétent ; ne pas inventer conformité CNDP/RGPD/PCI ni durée légale universelle. |
| D-14 | Isolation de processus par suppression de variables seulement | Mesure appliquée contre l’héritage de secrets ; isolation du réseau/FS/UID distincte, encore à démontrer sur l’hébergement. |
| D-15 | Une ancienne confirmation suffit aux opérations administratives | Preuve entière, non future, expirée dès la borne de 900 secondes au maximum ; connexion effaçant l’ancienne élévation et confirmation renouvelant la session. Les tests d’autorisation PostgreSQL restent requis ; cela ne remplace pas un MFA résistant au phishing. |
| D-16 | Le nom du mailer suffit à garantir la confidentialité | Contrôler le transport après application des options MAIL_URL avec le parseur Laravel ; rejeter log, failover, pilote historique et vérification TLS désactivée. SMTP/TLS effectif et délai borné sont vérifiés dans le diagnostic de production. L’envoi réel et la supervision de la queue notifications restent à qualifier. |

## Sources primaires consultées pour les arbitrages

- [OWASP ASVS](https://owasp.github.io/www-project-application-security-verification-standard/) : édition stable et portée du référentiel.
- [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html) : mots de passe, vérification et récupération.
- [Alpine CSP](https://alpinejs.dev/advanced/csp) : différence entre distribution standard et distribution CSP.
- [MDN Set-Cookie](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie) : conditions des préfixes.
- [PostgreSQL SSL](https://www.postgresql.org/docs/18/libpq-ssl.html) et [privilèges](https://www.postgresql.org/docs/18/ddl-priv.html) : identité du serveur et séparation des rôles.
- [ClamAV scanning](https://docs.clamav.net/manual/Usage/Scanning.html) : service local et scan de flux.
- [Pwned Passwords](https://haveibeenpwned.com/API/v3#PwnedPasswords) : préfixe de cinq caractères et padding.
- [Semgrep, règles locales](https://docs.semgrep.dev/running-rules) : portée des règles versionnées utilisées.
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) : nouvelle authentification avant les actions sensibles.
- [Symfony Mailer, vérification TLS](https://symfony.com/doc/current/mailer.html#tls-peer-verification) : chiffrement exigé et vérification du serveur SMTP ; la résolution des options a aussi été relue dans le framework installé.
- [Gitleaks 8.30.1](https://github.com/gitleaks/gitleaks/blob/v8.30.1/README.md) : règles par défaut conservées et exceptions combinant chemin exact et valeur synthétique exacte.

Ces références ne valident pas l’hébergement. Les sources des quatre documents
restent identifiées par fichier, empreinte et lignes dans le registre ; aucune
affirmation de version issue d’un exemple n’est transformée en résultat de test.
