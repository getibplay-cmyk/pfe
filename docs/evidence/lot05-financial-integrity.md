# Preuves du lot 05 — intégrité financière et opérationnelle

## Workflow

```text
returned → facture brouillon → issued → allocation/paiement
         → caution reçue → retenue/remboursement → closed
```

La clôture exige `invoice.status=paid`, `balance_due=0`, aucun paiement `pending` et `deposit_received = deposit_retained + deposit_refunded` après contrepassations.

## Immutabilité financière

- triggers `invoices_financial_immutability` et `invoice_lines_financial_immutability` ;
- triggers append-only sur allocations et caution ;
- paiement posté corrigé par une écriture opposée ;
- clés d’idempotence uniques par tenant ;
- test `Lot05FinancePhaseATest` : facture/ligne/caution immuables et clôture directe refusée.

## Bloc maintenance

L’approbation crée un bloc `maintenance`. Le test de conflit utilise un bloc de réservation actif et vérifie le refus métier issu de la violation GiST PostgreSQL. La fin libère uniquement le bloc de l’ordre concerné.

## Confidentialité assurance

Le numéro de police et la référence assureur sont chiffrés. Aucun document n’obtient d’URL publique. Les montants et responsabilités restent des décisions humaines.

## Limites

Pas de comptabilité générale, grand livre, paie, passerelle bancaire, déclaration fiscale, décision juridique automatisée ou IA.
