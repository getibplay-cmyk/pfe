# Preuve — chiffrement et fichiers privés du lot 02

La suite PostgreSQL vérifie sans afficher les valeurs sensibles :

- la valeur chiffrée diffère de la donnée fictive fournie ;
- l’identité est masquée sans permission ;
- les audits ne contiennent ni numéro d’identité ni permis ;
- le fichier existe sur le disque `local` privé et pas sur le disque `public` ;
- `/storage/{chemin}` retourne 404 ;
- un téléchargement cross-tenant retourne 404 ;
- un téléchargement autorisé crée un `document_access_log` ;
- les scripts, doubles extensions et chemins clients sont refusés ;
- une nouvelle version reçoit le numéro suivant.

Commande :

```powershell
php artisan test
```

Résultat avant validation finale : **49 tests réussis, 148 assertions**.
