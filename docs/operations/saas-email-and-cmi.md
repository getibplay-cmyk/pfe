# E-mails de compte et paiement d’abonnement CMI

## État livré

Le code couvre désormais les deux parcours e-mail et le paiement d’abonnement
sur la page hébergée de CMI :

- vérification obligatoire de l’adresse avant tout espace métier ou plateforme ;
- nouvel envoi limité, lien signé et expiration Laravel ;
- réinitialisation de mot de passe avec réponse anti-énumération, mot de passe
  fort, révocation des sessions et audit ;
- tentative CMI créée depuis le tarif figé de l’abonnement courant ;
- formulaire `ver3` signé côté serveur ;
- callback sans CSRF mais signé, limité, idempotent et rapproché du montant, de
  la devise, du marchand, de la commande et de l’abonnement ;
- écriture du registre SaaS et activation/renouvellement uniquement après un
  callback CMI valide ;
- aucun stockage du payload CMI ou d’une donnée de carte.

Le flux est **fermé par défaut**. Il ne devient réellement appelable qu’après
affiliation CMI et configuration du kit marchand courant. La documentation CMI
publique indique que ce kit et l’accès à l’environnement de test sont remis au
commerçant pendant l’intégration :

- <https://www.cmi.co.ma/fr/solutions-paiement-ecommerce>
- <https://www.cmi.co.ma/sites/default/files/2024-09/cmi_solutions_livret_e-com.pdf>

## Configurer l’envoi SMTP

Conserver les secrets uniquement dans l’environnement de déploiement :

```dotenv
APP_URL=https://app.exemple.ma
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.exemple.ma
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@exemple.ma
MAIL_FROM_NAME="BELKHIR SPACE"
SAAS_CONTACT_EMAIL=commercial@exemple.ma
```

Effectuer un essai vers une boîte contrôlée, vérifier le lien, son expiration et
la bonne configuration SPF/DKIM/DMARC du domaine. Le pilote `log` de
`.env.example` reste adapté au développement, pas à une recette d’e-mail réel.

## Configurer le bac à sable CMI

Déposer le kit technique **sans secret** dans le dossier Drive du projet pour
conserver sa version, puis comparer ses champs, son algorithme et les réponses
de callback à `CmiHostedGateway` et `CmiSignature`. Ne jamais placer la clé du
magasin dans Drive, Git, un notebook ou une capture.

```dotenv
APP_URL=https://recette.exemple.ma
CMI_PAYMENT_ENABLED=true
CMI_PAYMENT_MODE=sandbox
CMI_PAYMENT_URL=https://testpayment.cmi.co.ma/fim/est3Dgate
CMI_MERCHANT_ID=...
CMI_STORE_KEY=...
CMI_MERCHANT_KIT_VERSION=version-exacte-du-kit-recu
CMI_STORE_TYPE=3D_PAY_HOSTING
CMI_TRANSACTION_TYPE=PreAuth
CMI_ATTEMPT_TTL_MINUTES=30
CMI_SUCCESS_ACKNOWLEDGEMENT=ACTION=POSTAUTH
CMI_FAILURE_ACKNOWLEDGEMENT=ACTION=DECLINE
```

La version du kit est un verrou volontaire : sans elle, l’interface indique que
la passerelle n’est pas prête et refuse de créer une tentative.

Le callback public à déclarer chez CMI est :

```text
https://recette.exemple.ma/billing/cmi/callback
```

`APP_URL` doit être l’URL HTTPS réellement joignable par CMI. Les URL de retour
n’accordent aucun droit et ne confirment jamais le paiement ; seul le callback
signé le fait.

## Recette avant production

1. Comparer le vecteur de signature du kit avec `tests/Unit/CmiSignatureTest.php`.
2. Exécuter un paiement accepté dans le bac à sable et vérifier une seule ligne
   `saas_payments`, une tentative `paid` et un événement `accepted`.
3. Rejouer deux fois le même callback : aucune seconde écriture ne doit exister.
4. Modifier successivement le montant, la devise, la commande et la signature :
   aucune écriture de paiement ne doit être créée.
5. Simuler un refus et une tentative expirée.
6. Vérifier le journal global et le rapprochement avec l’espace marchand CMI.
7. Faire confirmer par CMI les valeurs `TranType` et les accusés
   `ACTION=POSTAUTH` / `ACTION=DECLINE` du kit reçu.
8. Basculer vers l’URL de production uniquement après validation formelle du bac
   à sable et rotation des secrets de test.

## Remboursements et rapprochement

Un paiement CMI ne peut pas être contrepassé par le formulaire manuel : une
écriture locale ne rembourserait pas la carte. Le remboursement doit d’abord
être exécuté et confirmé dans l’espace marchand CMI, puis faire l’objet d’un
rapprochement documenté. Les journaux locaux ne contiennent que les empreintes,
codes, références et horodatages nécessaires à cette preuve.

## Pourquoi Colab n’intervient pas

Colab reste réservé aux expériences et entraînements scientifiques du PFE. Les
e-mails, migrations PostgreSQL, signatures CMI, callbacks et tests Laravel sont
des fonctions applicatives : ils sont validés par PHPUnit, Node et la CI GitHub.
Exécuter ou stocker une clé marchande dans Colab créerait une exposition de
secret sans bénéfice technique.
