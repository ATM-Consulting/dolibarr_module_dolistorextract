# Change log for DolistoreExtract

## Unreleased


## Release 1.6
- FIX : Feedback refacto of DolistoreExtract module
    + Email is now only on the contact record
    + Postal address is now only on the company record - 1.6.1 - *01/08/2025*
- NEW : Redesign of the DolistoreExtract module
    + Removed the function that created a category for each module purchased by a third party
    + Added a function to list each purchased module in the sales list - 1.6.0 - *22/07/2025*
  
## Release 1.5
- NEW : Compat V20 & V21 & Merge modification about mail structure - 1.5.0 - *25/02/2025*

## Release 1.4
- FIX : Addition of a check to prevent several emails from the same order from being processed several times (DA025745) - 1.4.2 - *30/12/2024*
- FIX : Select user pour import init websale + ajout des dates d'achats dans msg d'erreurs - 1.4.1 - *25/09/2024*
- NEW : Ajout de la gestion de l'onglet vente d'un module + Script init onglet vente module - 1.4.0 - *17/09/2024*

## Release 1.3
- FIX : Warning PHP 8.2 - 1.3.1 - *25/03/2024*
- NEW : compatibility to v19 and php8 - 1.3 - *07/03/2024*
  Attention perte compatibilité inférieure V19

## Release 1.2
- FIX : changement de champ pour les prospects importés - 1.2.11 - *06/07/2023*
- FIX : tâche cron dolistorextract => fix gestion des erreurs - 1.2.10 - *28/03/2023*
- FIX : Import contact problem for V15  - 1.2.9 - *24/06/2022*
- FIX : everyday same thirdparty creation because of contact fetch return 2 if several matches, now if several matches we use the oldest thirdparty - 1.2.8 - *06/10/2021*
- FIX : fails to create thirdparty / contact when more than one third party name matches
  name from e-mail - 1.2.7 - *10/05/2021*
- no ChangeLog up to this point
