# Sécurité du compte et interface

Le changement d’adresse e-mail demande le mot de passe actuel. Une modification du
nom seul ne le demande pas. Le profil n’accepte que le nom et l’e-mail ; la nouvelle
adresse doit toujours être vérifiée. Les tentatives de modification du profil et
du mot de passe ont chacune leur limitation de débit.

Le changement du mot de passe, y compris le remplacement initial obligatoire,
révoque les autres sessions et les connexions mémorisées. Le mot de passe actuel
est revérifié sous verrou. La session courante change d’identifiant ; une preuve
MFA valide est conservée pour cette session uniquement. Le code MFA reste
nécessaire à une nouvelle connexion lorsque la double authentification est active.

Dans **Mon profil → Sécurité du compte**, l’utilisateur voit l’état de la double
authentification et de la vérification de l’e-mail. Les 50 sessions actives les
plus récentes présentent le navigateur, le système, l’activité et les détails de
l’appareil. Ces libellés sont indicatifs : le navigateur peut déclarer un autre
nom, et ce nom ne sert jamais à autoriser un accès.

**Déconnecter les autres appareils** demande une confirmation récente du mot de
passe et conserve la session courante. La requête ignore toute tentative de cibler
un autre utilisateur ou une autre entreprise. Les révocations sont auditées sans
jeton ni code secret. Les pages de sécurité restent privées et sans transmission
de référent, y compris après expiration de la session.

Le mode MFA obligatoire pour les administrateurs conserve un chemin fonctionnel
vers la confirmation du mot de passe puis l’activation. Il ne permet pas de
contourner un MFA déjà activé.

## Interface

- Titres, cartes de statistiques et priorités partagent des surfaces plus lisibles.
- La barre supérieure s’adapte aux petits écrans ; la navigation conserve ses
  permissions serveur et ses entrées communes desktop/mobile.
- Le menu mobile empêche l’interaction avec le contenu en arrière-plan, conserve
  le focus dans le menu et restaure le défilement à sa fermeture ou au passage
  sur un écran large.
- Un retour arrière ne réactive plus un bouton initialement désactivé par une
  règle métier. Les états de chargement répétés restaurent l’état initial.
- Les nouveaux messages sont disponibles en français et en arabe ; les icônes
  directionnelles suivent le sens de lecture et les animations réduites sont respectées.

## Vérification et mise à jour

Les tests ciblent le parcours réel de confirmation MFA, la révocation entre
appareils et entre entreprises, les connexions mémorisées, les changements
d’e-mail, l’échappement des descriptions d’appareils et la navigation mobile.
Le job CI exécute ces tests avec PostgreSQL, puis la suite complète, Pint et Vite.
Ses résultats publiés font foi ; une compilation ne prouve pas à elle seule une
validation visuelle complète ni un audit de sécurité exhaustif.

Aucune migration ni nouvelle dépendance n’est nécessaire pour ce lot. Après la
mise à jour du code : `php artisan optimize:clear`, puis `npm.cmd run build` sous
PowerShell (ou `npm run build` sous Linux). Le fonctionnement des révocations
s’appuie sur les sessions PostgreSQL prévues par l’architecture.

Références de conception : [authentification Laravel 12](https://laravel.com/docs/12.x/authentication#invalidating-sessions-on-other-devices)
et [réauthentification OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#changing-a-users-registered-email-address).
