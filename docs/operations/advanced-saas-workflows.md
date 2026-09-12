# Les dix améliorations SaaS

## Installer la mise à jour

Ce guide concerne la branche `feature/saas-advanced-workflows`. La récupérer après sa publication, ou récupérer `main` après sa fusion. Une branche locale non publiée ne peut pas être obtenue avec `git pull`.

Depuis le dossier du projet dans PowerShell :

```powershell
composer install --prefer-dist --no-interaction
npm.cmd ci
php -m | Select-String -Pattern "pdo_pgsql|pgsql|gd|zip|mbstring"
php artisan migrate
php artisan optimize:clear
npm.cmd run build
php artisan queue:restart
```

Utiliser la procédure de sauvegarde existante avant de migrer une base utilisée. Conserver la clé `APP_KEY`, qui protège notamment les secrets MFA et contacts chiffrés. Ne pas relancer `key:generate` sur une installation existante. Les sept migrations sont additives ; aucune suppression de données ni migration destructive n'est nécessaire.

Le scheduler et les workers existants restent requis pour les abonnements, notifications et traitements IA. Aucun nouveau package Composer/npm n'est nécessaire. L'ajout et l'export d'images exigent les extensions PHP GD et ZIP.

## Accès et utilisation

| Amélioration | Où la trouver | Utilisation |
|---|---|---|
| 1. MFA et appareils | Mon profil → Double authentification et appareils | Ajouter la clé à une application TOTP, valider et conserver les dix codes de secours ; fermer une session inconnue |
| 2. Corrections IA | Analyse couleur, plaque ou dommages → Vérifier les annotations | Corriger puis valider une annotation ; préparer un jeu privé depuis Annotations vérifiées |
| 3. Inspection mobile | Contrat → États des lieux guidés | Relever compteur/carburant/conditions ; photographier six angles ; reprendre le brouillon puis confirmer la fin |
| 4. Planning interactif | Parc automobile → Planning de flotte | Déplacer une réservation ou utiliser le lien au clavier/mobile ; vérifier le tarif et confirmer |
| 5. Portail actif | Lien personnel du locataire → Mon contrat | Lire et accepter le contrat ; demander une prolongation ; lire et accepter ensuite l'avenant |
| 6. Réservation publique | Administration → Catalogue public | Choisir l'agence et les véhicules ; publier ; examiner les demandes puis créer un brouillon |
| 7. Coût complet | Véhicule → Coûts de possession / Finance → Rentabilité | Versionner achat, valeur résiduelle, amortissement, assurance et coûts non saisis |
| 8. Qualité IA | Aide à la décision → Qualité des modèles | Comparer analyses, abstentions et corrections par agence/modèle sur 7, 30 ou 90 jours |
| 9. Espace personnel | Recherche et favoris ; Ctrl/⌘ + K | Rechercher un dossier autorisé, garder jusqu'à 20 favoris et 15 filtres personnels |
| 10. Français/arabe | Sélecteur de langue de chaque espace | Choisir la langue ; l'arabe active RTL et les messages de validation correspondants |

Le départ exige un contrat accepté ; le retour exige un contrat actif. Six vues sont proposées : avant, arrière, gauche, droite, habitacle et compteur. Une justification d'au moins dix caractères est nécessaire si des vues manquent. Limites : 30 photos par inspection, JPEG/PNG/WebP de 8 Mo maximum et dimensions contrôlées. Les anciennes prises restent privées ; les vues retenues à la finalisation sont figées.

« Brouillon enregistré » confirme une sauvegarde serveur. Attendre ce message avant de fermer la page. Sur un autre appareil, la dernière version enregistrée est reprise. Un conflit de révision demande de recharger ; une coupure ne garantit pas la récupération des modifications non enregistrées.

## Sécuriser les connexions

Configurer `SESSION_DRIVER=database` pour gérer les appareils. MFA est disponible pour les comptes entreprise et plateforme. Pour l'imposer aux administrateurs plateforme et propriétaires d'entreprise :

```dotenv
SECURITY_MFA_REQUIRE_ADMINS=true
```

Un compte concerné doit terminer l'activation avant de reprendre les fonctions protégées. La clé d'activation expire après dix minutes ; TOTP utilise six chiffres et une période de trente secondes. Les tentatives sont limitées et le rejeu refusé. Conserver les codes de secours hors du SaaS ; chacun ne sert qu'une fois.

L’activation ou la désactivation de MFA incrémente la version de sécurité et ferme les autres sessions. La révocation d’un appareil supprime sa session et renouvelle le jeton de connexion persistante du compte. Les secrets et codes de secours ne sont jamais audités. Le changement de langue conserve les identifiants et données saisis.

## Planning, portail et catalogue

Un déplacement concerne une réservation confirmée sans contrat, dans la même agence et devise. L'aperçu expire après dix minutes. La confirmation revérifie dossier, prix et disponibilité ; un conflit conserve le dossier d'origine. Le nouveau tarif est figé et les deux dossiers restent liés. Une confirmation déjà enregistrée retrouve le dossier de remplacement même après expiration de l’aperçu ; une proposition non traitée reste soumise à son délai.

Le locataire accepte une version prête après lecture de son document privé. Le fichier d’un contrat ou d’un avenant ne peut pas recevoir une autre version de fichier ; toute correction passe par une nouvelle version contractuelle avec son propre PDF. Cette règle est vérifiée par la policy, l’action d’ajout et PostgreSQL. Une demande de prolongation ne change aucune date. L'agence propose supplément, kilomètres supplémentaires et PDF d'avenant correspondant ; la proposition reste valable vingt-quatre heures. Le locataire doit l'accepter avant l'extension du planning et du contrat. Une demande ne peut dépasser trente jours après le retour prévu. Les contrats déjà facturés sont exclus.

Le catalogue est désactivé tant que le propriétaire ne publie pas explicitement ses informations. Seuls les véhicules actifs sélectionnés de l'agence choisie sont présentés, avec leurs caractéristiques publiques. Plaques, identités et documents restent privés. Le devis expire après vingt minutes.

Une demande publique conserve les coordonnées chiffrées et ne crée aucun bloc. Son identifiant UUID est conservé dans la colonne dédiée de l’audit, sans coordonnées personnelles. L'agence peut l'écarter ou créer une réservation brouillon avec un client existant autorisé ou un nouveau client à vérifier. La vérification du client, le conducteur, les documents et la confirmation canonique restent nécessaires. Demandes et prolongations à examiner apparaissent au tableau de bord.

## Données et qualité IA

Valider la couleur réelle, la transcription complète de la plaque ou tous les cadres de dommages visibles. Une annotation vide de dommages confirme leur absence après vérification complète. Les coordonnées sont normalisées ; dessin au doigt/souris et édition numérique sont disponibles.

Un export contient au plus 50 images et 64 Mo. Associer chaque analyse au bon véhicule avant export. Le ZIP privé contient les images réencodées et leur index. L'autorisation d'apprentissage et celle du partage avec la plateforme sont distinctes. Une correction ultérieure révoque les jeux dépendant de l'ancienne annotation.

Les corrections sont mesurées uniquement sur les suggestions comparables et revues. Les analyses non revues ne comptent pas comme correctes. Les lectures de plaque incomplètes/ambiguës sont des abstentions. Le F1 des dommages compare les cadres avec un IoU minimal de 0,50. Une comparaison temporelle exige vingt annotations comparables dans chacune des périodes.

Les alertes indiquent plus de 20 % de corrections ou une hausse de dix points. Elles orientent une revue sans déclencher de déploiement. Utiliser le [guide Colab/Drive](../intelligence/model-retraining-workbench.md) pour préparer une campagne, entraîner et comparer un candidat sur un test indépendant.

## Lire la rentabilité

Facturé, encaissé et dépensé restent les valeurs des registres existants. La deuxième lecture estime :

`facturé − dépenses approuvées hors achat associé − amortissement − assurance non saisie − autres coûts non saisis`.

L'amortissement répartit achat moins valeur résiduelle sur les jours calendaires de la durée choisie ; il s'arrête à son terme. Assurance et autres coûts sont proratisés selon les jours de l'année et du mois. Une dépense d'achat approuvée, de type Autre, peut être associée au profil pour éviter d'additionner achat et amortissement.

Le complément d'assurance est le budget estimé de la période moins les dépenses d'assurance approuvées, avec un minimum de zéro. Ne pas additionner les estimations de fenêtres différentes : un paiement annuel peut se concentrer dans un seul mois. Les autres coûts mensuels représentent uniquement des coûts non déjà saisis.

Chaque changement crée une version datée. La couverture en jours et les hypothèses manquantes restent visibles ; les devises sont séparées. Les estimations ne produisent aucun mouvement comptable et ne modifient pas les factures.

## Vérifier avant intégration

Les tests d'intégration exigent PostgreSQL et une base **`rentfleet_test`** dédiée, configurée dans `.env.testing` hors Git. Aucun remplacement par SQLite ni utilisation de `rentfleet` n'est autorisé.

```powershell
php artisan test --filter="AccountSecurity|WorkspaceExperience|GuidedInspection|VisionAnnotationsAndQuality|ReservationReplan|ActiveCustomerPortal|PublicBooking|VehicleEconomics|LocalizationCatalog"
php artisan test
vendor\bin\pint --test
npm.cmd run test:js
npm.cmd run build
```

La CI conserve le contrôle ciblé et la suite complète. Les tests SMTP/CMI utilisent des simulations contrôlées ; la recette réelle reste celle des guides existants. Vérifier aussi les écrans français/arabe sur mobile et les documents privés dans l'environnement de recette.
