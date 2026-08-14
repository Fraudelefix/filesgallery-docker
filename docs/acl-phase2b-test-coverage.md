# Phase 2B — matrice de couverture

État audité sur `73306d1`. « Runtime » désigne le conteneur Apache réel, pas un helper PHP.

| Scénario | Unitaire | Runtime HTTP | Reprise | État initial | Notes |
| --- | --- | --- | --- | --- | --- |
| Connexion utilisateur | Non | Oui | Non | TESTED | Formulaire, token et cookie réels |
| Listing / fichier autorisé | Oui | Oui | Non | TESTED | `victor`, `Family/Shared` |
| Fichier, download, texte refusés | Oui | Oui | Non | TESTED | Corps autorisés et secrets refusés |
| Ancêtre traverse-only / descendant lisible | Oui | Partiel | Non | PARTIALLY TESTED | Le listing le couvre partiellement |
| Preview refusée / autorisée / cache | Oui | Refus seul | Non | PARTIALLY TESTED | Aucun média de preview réel ni cache partagé |
| Hash menu stale / étranger | Oui | Non | Non | PARTIALLY TESTED | Cache-miss implémenté, non exercé HTTP |
| Cache broad/narrow et changement ACL | Oui | Non | Non | NOT TESTED | À ajouter |
| Symlink, canonique hors racine | Oui | Non | Non | PARTIALLY TESTED | Adapter uniquement |
| Traversal, encodé, backslash | Oui | Partiel | Non | PARTIALLY TESTED | Seulement `../` initialement |
| Non authentifié / utilisateur défaut | Non | Partiel | Non | PARTIALLY TESTED | Non authentifié vérifié ; défaut désactivé par conception |
| ACL missing / empty / malformed | Partiel | Oui | Non | TESTED | Authentification puis listing fermé |
| Admin valide / invalide | Oui | Oui | Non | TESTED | Bypass lisible, traversal refusé |
| Bypass direct `/media`, `/config`, cache | Non | Partiel | Non | PARTIALLY TESTED | `/media` seul initialement |
| Redémarrage / recréation avec ACL | Non | Oui | Oui | TESTED | Listing ACL après restart isolé et recreate |
| SHA erroné, ancre, syntaxe, marqueurs | Non | Oui | N/A | TESTED | Entrypoint et patch déterministe |

Les scénarios marqués incomplets sont promus en contrôles runtime dans le workflow final. Les fonctionnalités premium (ZIP/mass-download) ne sont pas activées : elles restent hors couverture par choix, sans contournement de licence. Les droits d’écriture et l’interface ACL restent hors périmètre.
