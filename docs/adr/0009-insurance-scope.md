# ADR 0009 — Périmètre assurance

## Statut

Accepté — lot 05, complété par le lot 06F-C2.

## Décision

Le module suit compagnies, polices, garanties configurables, échéances et sinistres simples. Le numéro de police et la référence assureur sont chiffrés ; les listes n’affichent qu’un masque. Les documents réutilisent exclusivement le stockage privé contrôlé.

Les montants demandés, approuvés et réglés sont saisis explicitement. RentFleet ne détermine ni responsabilité juridique, ni caractère obligatoire d’une garantie facultative.

Le cycle d’une police est explicite et append-only : création forcée en
`draft`, activation contrôlée, expiration planifiée ou annulation motivée. Une
activation exige une compagnie active, un véhicule du même périmètre, une
période valide, au moins une garantie non archivée et une preuve privée courante
dont le fichier, la taille et l’empreinte SHA-256 sont vérifiables.

Une police active ne peut pas chevaucher une autre police active de même type
pour le même véhicule. Les polices terminales et leurs historiques sont
immuables dans PostgreSQL. Un renouvellement crée un nouveau brouillon relié à
la police historique ; il ne copie jamais les documents privés.

La date réelle de l’incident est obligatoire pour tout nouveau sinistre et doit
appartenir à la période de couverture. Les transitions du sinistre restent
humaines et conservent la convention selon laquelle `under_review` peut avoir
un `reviewed_at` nul jusqu’à l’approbation ou au rejet.

## Hors périmètre

Décision juridique automatisée, tarification assureur, transmission assureur, indemnisation bancaire et document public.
