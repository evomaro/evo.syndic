# Cartographie des tâches financières

Cette cartographie donne une page d’entrée unique à chaque tâche financière. Les autres modules peuvent proposer un lien contextuel, mais ne doivent pas présenter une seconde action indifférenciée.

| Tâche utilisateur                                 | Page d’entrée                        | Périmètre affiché                                    |
| ------------------------------------------------- | ------------------------------------ | ---------------------------------------------------- |
| Encaisser un paiement résident                    | Finances → Encaisser                 | Recouvrement copropriétaires                         |
| Consulter les impayés et relevés                  | Finances                             | Créances résidents, appels de fonds et encaissements |
| Générer un appel de fonds                         | Finances → Appels de fonds           | Facturation des copropriétaires                      |
| Créer et suivre un budget                         | Dépenses & fournisseurs → Budgets    | Prévision et consommation des charges                |
| Enregistrer une facture fournisseur               | Dépenses & fournisseurs → Factures   | Comptes fournisseurs                                 |
| Payer un fournisseur                              | Dépenses & fournisseurs → Règlements | Décaissements et affectation aux factures            |
| Consulter le solde bancaire ou de caisse          | Finances → Comptes financiers        | Solde opérationnel par compte                        |
| Saisir ou consulter une écriture en partie double | Comptabilité                         | Journaux, comptes et écritures                       |
| Produire la balance ou le grand livre             | Comptabilité → Rapports              | Justification comptable                              |
| Clôturer un exercice comptable                    | Comptabilité → Clôture               | Contrôles, clôture et report à nouveau               |
| Gérer les tantièmes                               | Finance → Tantièmes                  | Clés servant aux répartitions et appels              |

## Règles de différenciation

- **Finances** répond à « combien les résidents doivent-ils et combien avons-nous encaissé ? ».
- **Dépenses & fournisseurs** répond à « que devons-nous payer, à qui et par rapport à quel budget ? ».
- **Comptabilité** répond à « comment ces opérations sont-elles justifiées dans les journaux et les états comptables ? ».
- Une opération fournisseur se crée dans Dépenses. Sa conséquence comptable est consultable depuis Comptabilité, sans seconde saisie manuelle concurrente.
- Une écriture comptable liée à une source affiche un lien vers cette source. Les écrans d’aide doivent reprendre ces trois définitions.

## Découverte requise avant un mode simplifié

Le choix d’une vue simplifiée reste une décision produit et ne doit pas être déduit du code. Interroger 5 à 10 gestionnaires non-comptables sur trois scénarios : consulter la trésorerie, connaître les sommes dues, et comprendre une dépense. Documenter qui ouvre réellement Comptabilité, les informations recherchées et les écrans évités. Si la majorité n’utilise pas les journaux, prototyper une vue synthétique par défaut et réserver le mode avancé au rôle Comptable.
