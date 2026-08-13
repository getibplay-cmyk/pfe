# J15-A — preuve de reproductibilité et bundle de release

## Décision

J15-A produit une **preuve d’intégration logicielle** liée à un commit Git exact.
Il ne relance aucune expérience scientifique, ne crée aucun modèle, ne valide
pas un modèle sur les données RentFleet et n’autorise aucune action métier.
Une éventuelle régénération des sorties scientifiques externes relève d’un
J15-B distinct, avec autorisation et protocole propres.

Le périmètre reste fermé :

- Drive et Colab ne sont ni lus ni modifiés ;
- aucune inférence, aucun entraînement et aucun solveur ne sont exécutés ;
- aucune écriture métier ou migration supplémentaire n’est introduite ;
- les preuves J12 et les contrats J11/J14 existants sont seulement hachés comme
  matériaux de provenance ;
- `ready_for_saas`, `production_allowed`, `automatic_action_allowed` et
  `operational_business_write_allowed` restent à `false`.

## Contrat du manifeste

La commande `intelligence:j15-release-evidence` consomme exclusivement :

1. un SHA Git complet et le nom du dépôt ;
2. le rapport JUnit de la suite réussie ;
3. le JSON réussi de `rentfleet:doctor` ;
4. une liste fermée de lockfiles, contrats, schémas, fixtures, workflow CI et
   manifeste Vite.

Elle refuse un SHA abrégé, une suite sans assertion, tout échec PHPUnit, un
diagnostic différent de `ok`, un matériau absent ou un document XML avec
`DOCTYPE`. Les détails potentiellement sensibles de Doctor ne sont pas recopiés :
seuls les nombres de contrôles `pass`, `warn` et `fail` sont conservés.

Le schéma fermé est
`docs/intelligence/schemas/j15-release-evidence-v1.0.0.json`. Le manifeste :

- trie récursivement les clés des objets ;
- conserve l’ordre des listes ;
- utilise UTF-8, les `/` non échappés et un unique saut de ligne final ;
- exclut l’heure, l’identifiant du run, la tentative et les caractéristiques du
  runner ;
- inventorie les matériaux par chemin trié et SHA-256.

Ainsi, les mêmes sources, résultats agrégés et matériaux produisent exactement
les mêmes octets et le même SHA-256.

## Bundle produit

Le dossier de sortie contient exactement les preuves suivantes :

| Fichier | Rôle |
|---|---|
| `j15-release-manifest.json` | Manifeste déterministe, bornes de sécurité et résultats agrégés |
| `j15-ci-run.json` | Enveloppe distincte du run : commit, workflow, run, tentative, runner et version PHP |
| `SHA256SUMS` | Empreintes GNU SHA-256 des deux fichiers JSON, utilisées comme sujets de l’attestation |

L’enveloppe est volontairement distincte : son identité de run est volatile,
alors que le manifeste doit rester reproductible. Aucune variable d’environnement
n’est collectée en masse. La commande ne lit qu’une liste blanche de métadonnées
GitHub Actions (`GITHUB_WORKFLOW`, `GITHUB_RUN_ID`, `GITHUB_RUN_ATTEMPT`,
`GITHUB_EVENT_NAME`, `RUNNER_OS`, `RUNNER_ARCH`).

Exemple local après production des deux rapports :

```powershell
php artisan intelligence:j15-release-evidence `
  --source-commit=<SHA-GIT-COMPLET> `
  --repository=getibplay-cmyk/pfe `
  --junit=storage/app/j15-tests.xml `
  --doctor=storage/app/j15-doctor.json `
  --output=storage/app/j15-release-evidence
```

Vérification du bundle sous Linux :

```bash
cd storage/app/j15-release-evidence
sha256sum --check SHA256SUMS
```

## Chaîne CI et attestation

Le job protégé `quality` conserve son nom et ses contrôles historiques. Il
génère les rapports machine après les audits, le build, Pint, les migrations et
la suite PostgreSQL, puis crée et vérifie le bundle. Le bundle est téléchargeable
sur toute pull request, mais il reste explicitement **non signé** à ce stade.

Un job séparé `attest-release-evidence` s’exécute uniquement sur un `push` vers
`main`, après succès de `quality`. Il retélécharge le bundle immuable, revérifie
`SHA256SUMS`, puis produit une attestation de provenance SLSA/Sigstore avec
`actions/attest`. Les permissions OIDC et d’écriture d’attestation sont limitées
à ce job ; elles ne sont pas accordées au job de pull request. Toutes les actions
GitHub sont référencées par SHA de commit complet.

La provenance prouve quel workflow GitHub a construit les sujets à partir d’un
commit. Elle ne transforme pas une preuve logicielle en validation scientifique
ou en homologation de production.

## Références

- GitHub, *Using artifact attestations to establish provenance for builds* :
  <https://docs.github.com/actions/security-for-github-actions/using-artifact-attestations/using-artifact-attestations-to-establish-provenance-for-builds>
- GitHub, *Secure use reference* :
  <https://docs.github.com/en/actions/reference/security/secure-use>
- SLSA, *Build provenance v1.2* :
  <https://slsa.dev/spec/v1.2/build-provenance>
- NIST, *Artificial Intelligence Risk Management Framework 1.0* :
  <https://nvlpubs.nist.gov/nistpubs/ai/NIST.AI.100-1.pdf>
- Google Research, *Model Cards for Model Reporting* :
  <https://research.google/pubs/model-cards-for-model-reporting/>
