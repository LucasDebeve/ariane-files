# Ariane

Drive souverain des formateurs BAFA/BAFD — **ariane.lucasdebeve.eu**.

Consultation et téléchargement sans compte grâce à un **code de partage commun**, propositions
d'ajout / modification / suppression ouvertes à tous (avec un simple nom), **validées par un
utilisateur certifié** (compte avec double authentification) avant publication.

- Symfony 7.4 (PHP 8.3), Doctrine, PostgreSQL 16 (`unaccent`, `pg_trgm`, plein texte `french`)
- Twig + Turbo + Stimulus + Tailwind CSS 4 (AssetMapper, aucune dépendance CDN, CSP stricte)
- Garage (S3) via Flysystem, ClamAV, Apache Tika (OCR Tesseract), Gotenberg, pdf.js, ZipStream
- Altcha (captcha sans pistage), `scheb/2fa-bundle` (TOTP), Caddy (celui du VPS, partagé), restic

Le cahier des charges et les décisions prises sont dans [`CLAUDE.md`](CLAUDE.md), le déploiement
dans [`docs/deploiement.md`](docs/deploiement.md).

## Démarrage en local

Prérequis : PHP 8.3 (intl, pdo_pgsql, zip), Composer, PostgreSQL 16 (ou `docker compose -f compose.dev.yaml up -d database`).

```bash
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console app:share-code:rotate --code=MON-CODE-LOCAL   # code de partage
php bin/console app:admin:create moi@example.org "Mon nom"    # compte admin (2FA à la 1re connexion)
php bin/console app:demo:load                                  # facultatif : données de démonstration
php bin/console tailwind:build --watch &                       # CSS
symfony serve   # ou : php -S 127.0.0.1:8000 -t public public/index.php
```

En développement, les fichiers sont stockés dans `var/storage/` et servis par des URL signées
expirantes (remplaçant les URL S3 présignées). L'antivirus est désactivé par défaut
(`ANTIVIRUS_DRIVER=none`, **refusé en production**) ; Tika et Gotenberg sont optionnels
(`TIKA_URL`, `GOTENBERG_URL`, voir `compose.dev.yaml`). Le worker asynchrone :

```bash
php bin/console messenger:consume async scheduler_default -vv
```

## Qualité

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse            # niveau 8
php bin/console doctrine:database:create --env=test && php bin/console doctrine:migrations:migrate --env=test -n
php bin/phpunit                       # unitaires + fonctionnels + bout en bout du workflow de validation
```

La CI GitHub Actions (`.github/workflows/ci.yml`) exécute tout cela et construit les images Docker.

## Commandes utiles

| Commande | Rôle |
|---|---|
| `app:share-code:rotate [--code=…]` | Génère un nouveau code de partage (l'ancien cesse immédiatement de fonctionner) |
| `app:admin:create <email> [nom] [--password-stdin]` | Crée ou promeut l'administrateur |
| `app:proposals:approve-all <email> [--comment=…] [--dry-run] [--allow-own]` | Valide toutes les propositions en attente au nom d'un compte certifié (`--allow-own` : y compris les siennes) |
| `app:purge` | Purge corbeille (30 j) et fichiers des propositions refusées (7 j) — planifié chaque nuit |
| `app:documents:reprocess` | Relance extraction de texte / aperçus manquants |
| `app:demo:load` | Données de démonstration (hors production) |

## Architecture

```
src/
├── Controller/        Accès (code), documents, dossiers, propositions, certifié, admin
├── Entity/ Enum/      User, Folder, Document, Tag, ChangeRequest, DownloadStat, AuditLog, ShareCode
├── Security/          ShareAccess (code haché), voters, UserChecker, CSP nonce, point d'entrée
├── Service/           ProposalService, ReviewService, DocumentSearch, ZipArchiveStreamer, Purger,
│                      Upload/FileInspector, Antivirus/ClamAV, TextExtraction/Tika, Preview/Gotenberg, Captcha/Altcha
├── Storage/           DocumentStorage (interface) + Flysystem, URL présignées S3 / signées (dev)
├── MessageHandler/    Extraction de texte et aperçu PDF après publication (asynchrone)
└── Scheduler/         Purge quotidienne
```
