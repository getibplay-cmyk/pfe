# Exécution HGB authentique depuis le SaaS

## Ce qui est réellement intégré

RentFleet peut exécuter le bundle privé
`demand_forecast_munich_j5_v1.0.joblib` depuis l’écran
`/intelligence/demand-forecasts`. Il ne s’agit ni d’un faux modèle, ni d’un
estimateur recréé, ni d’un réentraînement : le runtime accepte exclusivement
le fichier J5 de 6 401 204 octets portant le SHA-256 suivant :

```text
992217b4887623ca924a3dc36686c69ab616634aace64cf993ad50b61ace6802
```

Le fichier binaire reste hors de Git. `joblib` repose sur le mécanisme pickle ;
un fichier non fiable pourrait exécuter du code lors du chargement. RentFleet
vérifie donc la taille et l’empreinte avant de lancer Python, puis l’adaptateur
les vérifie une seconde fois avant `joblib.load`. Seul l’artefact provenant du
dossier privé contrôlé du projet doit être installé. Voir la documentation
officielle de
[scikit-learn sur la persistance des modèles](https://scikit-learn.org/1.6/model_persistence.html).

## Installation locale sous Windows

Depuis PowerShell à la racine du dépôt :

```powershell
py -3.12 -m venv .venv-demand
.\.venv-demand\Scripts\python.exe -m pip install --disable-pip-version-check --requirement scripts\intelligence\requirements-demand-forecast.txt
.\.venv-demand\Scripts\python.exe -m pip check
```

Récupérer ensuite le fichier exact depuis le dossier privé de modèles du
projet, puis demander à Laravel de le vérifier et de le copier dans son
stockage privé :

```powershell
php artisan rentfleet:demand-model:install "C:\chemin\demand_forecast_munich_j5_v1.0.joblib"
```

La commande refuse toute taille ou empreinte différente et ne désérialise pas
le fichier. Elle n’utilise `--replace` que si un fichier cible invalide a été
préalablement contrôlé.

Configurer `.env` avec l’interpréteur gelé :

```dotenv
DEMAND_FORECAST_RUNTIME_ENABLED=true
DEMAND_FORECAST_PYTHON_BINARY=C:\chemin\pfe\.venv-demand\Scripts\python.exe
QUEUE_CONNECTION=database
```

Le chemin du modèle n’a normalement pas besoin d’être configuré : la commande
l’installe sous `storage/app/private/intelligence/models`. Si un stockage privé
différent est nécessaire, définir `DEMAND_FORECAST_MODEL_PATH` avec un chemin
absolu non public avant l’installation.

Finaliser et vérifier :

```powershell
php artisan optimize:clear
php artisan migrate
php artisan rentfleet:doctor
php artisan queue:work --queue=intelligence,default --tries=1 --timeout=70
```

La ligne `Runtime HGB J5` du doctor doit être `pass` et indiquer le bundle
authentique vérifié, Python 3.12, NumPy 2.0.2, pandas 2.2.2,
scikit-learn 1.6.1 et joblib 1.5.3.

## Test depuis l’interface

1. Ouvrir **Intelligence → Prévision de demande**.
2. Créer un snapshot d’au moins 35 jours contenant au moins un départ observé.
3. Sur la ligne du snapshot, cliquer sur **Exécuter HGB authentique**.
4. Garder le worker de queue ouvert, puis actualiser la page.
5. Vérifier le passage `En attente du worker` → `Inférence HGB en cours` →
   `Inférence terminée`.
6. Vérifier l’encart **Inférence HGB réellement exécutée depuis le SaaS** et
   les sept résultats D+1 à D+7 avec P05, P50, P90 et P95.

Le registre PostgreSQL impose une seule exécution active par snapshot, une
lignée tenant/agence exacte et des transitions d’état fermées. Les erreurs
stockées sont des codes bornés ; ni stderr Python, ni chemin privé, ni secret
d’environnement ne sont conservés. La queue et les processus suivent les API
officielles [Laravel Queues](https://laravel.com/docs/12.x/queues) et
[Laravel Processes](https://laravel.com/docs/12.x/processes).

## Limite scientifique inchangée

L’exécution est réelle, mais reste `consultative_shadow`. Le modèle a été gelé
sur un benchmark public Munich ; aucune accuracy RentFleet locale n’est encore
revendiquée. Une prévision validée écrit uniquement dans les tables de preuve
Intelligence et conserve `NO_OPERATIONAL_ACTION` : elle ne modifie ni véhicule,
ni réservation, ni tarif, ni contrat.

## Test privé de l’artefact complet

Le dépôt teste sans bundle privé les contrats, le RBAC, la queue et la frontière
processus. Après installation locale, le smoke test authentique peut être lancé
avec le même environnement gelé :

```powershell
$env:DEMAND_FORECAST_MODEL_PATH="C:\chemin\demand_forecast_munich_j5_v1.0.joblib"
.\.venv-demand\Scripts\python.exe -m unittest -v tests.Python.test_demand_forecast_authentic_artifact
```

Ce test charge uniquement le fichier dont l’empreinte exacte est connue et
exige sept horizons valides. Il est ignoré en CI lorsque le bundle privé est
absent ; aucun substitut binaire n’est fabriqué.
