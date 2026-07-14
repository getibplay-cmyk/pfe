# Instructions RentFleet

Lire `RentFleet_Cahier_Architecture_Executable_Codex.md` avant toute
modification architecturale ou tout nouveau module.

- Laravel monolithique modulaire avec PostgreSQL ; ne pas utiliser SQLite pour la suite principale.
- Inspecter Git et préserver les changements existants.
- Développer par vertical slice avec migrations, autorisations et tests dans le même lot.
- Toute donnée métier est isolée par tenant côté serveur.
- `tenant_id` est dérivé du `TenantContext` et n’est jamais accepté depuis le client.
- Le global scope complète les policies et les contraintes ; il ne les remplace pas.
- Une route métier sans tenant doit échouer explicitement.
- Les platform admins utilisent uniquement les routes `/platform/*` dédiées.
- Les agency managers restent limités à leur agence.
- Ne jamais auditer mots de passe, tokens, secrets ou documents personnels complets.
- Ne pas ajouter de package, SPA ou microservice sans accord.
- Ne jamais commiter `.env`, `.env.testing`, secrets ou données personnelles.
- Exécuter les tests PostgreSQL pertinents, Pint et le build avant de terminer.
- Rapporter fichiers modifiés, commandes, résultats et limites.
