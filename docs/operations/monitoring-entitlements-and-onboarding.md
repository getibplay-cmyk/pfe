# Exploitation — supervision, plans et accueil SaaS

## 1. Configurer et lancer la supervision

Conserver les seuils par défaut ou définir les variables `OPERATIONS_*` du fichier
`.env.production.example`. Le cron doit exécuter `php artisan schedule:run` chaque
minute. Vérifier ensuite :

```bash
php artisan schedule:list
php artisan operations:scheduler-heartbeat
php artisan operations:monitor-platform --json
php artisan rentfleet:doctor --production
```

L’écran **Plateforme > Supervision** présente les incidents ouverts et leur
historique de transitions. Il signale séparément une collecte absente ou trop
ancienne, puisque celle-ci ne peut pas ouvrir elle-même son propre incident.
Traiter d’abord les incidents critiques. Les entrées
`failed_jobs` ne sont jamais supprimées automatiquement : diagnostiquer la cause,
relancer seulement le travail sûr puis archiver selon la procédure d’exploitation.

## 2. Définir un plan

Dans **Plateforme > Offres**, saisir les limites d’agences, utilisateurs,
véhicules et analyses mensuelles. Un champ vide signifie « sans limite » ; zéro
interdit toute nouvelle création correspondante. Sélectionner séparément les
assistants inclus. Le cœur locatif reste commun à toutes les offres ; dans ce lot,
la différenciation contractuelle porte sur ces ressources et assistances.

Lorsqu’un abonnement est créé, son tarif et ses droits sont figés. Pour appliquer
de nouveaux droits à un client existant, terminer son abonnement selon le flux
autorisé puis lui attribuer un nouvel abonnement. Ne jamais modifier la copie en
base directement.

## 3. Inviter une entreprise

1. Configurer un transport SMTP réel et vérifier sa réception avec un
   destinataire de recette contrôlé. Le transport `log` est refusé pour éviter
   d’écrire le lien personnel dans les journaux.
2. Ouvrir **Plateforme > Invitations d’accueil**.
3. Choisir l’e-mail, le plan, la durée de l’essai et la validité du lien.
4. Envoyer l’invitation et vérifier sa réception.
5. Le destinataire choisit son mot de passe et renseigne son entreprise ainsi que
   l’agence initiale.
6. Après validation, contrôler que l’invitation est « Acceptée », que l’essai est
   visible et que le propriétaire arrive sur **Démarrage guidé**.

Le lien ne doit jamais être recopié dans un ticket ou un journal. En cas de doute,
révoquer l’invitation et en créer une nouvelle. La commande
`onboarding:expire-invitations` consolide les invitations dépassées et est lancée
chaque heure par le scheduler.

Configurer aussi le serveur web et le proxy pour ne pas enregistrer les chaînes de
requête de `/commencer/*`. La réponse applique `Referrer-Policy: no-referrer`, mais
interdit aussi sa mise en cache ; la politique des journaux d’accès reste une
responsabilité d’exploitation.

## 4. Recette minimale

- dépasser chacun des quotas avec un compte d’essai et constater le refus sans
  création partielle ;
- vérifier qu’un assistant absent du plan est refusé même si son runtime est prêt ;
- vérifier qu’un ancien abonnement conserve ses quotas après modification du plan ;
- accepter un lien une fois, puis confirmer qu’un second envoi est refusé ;
- suspendre l’abonnement et confirmer que les données restent consultables mais
  qu’aucune nouvelle consommation soumise au plan n’est créée ;
- provoquer uniquement en environnement de test un travail échoué et vérifier
  l’ouverture puis la résolution de l’incident correspondant.
