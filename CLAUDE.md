# CLAUDE.md — Ariane, drive des formateurs BAFA/BAFD

> Application **Ariane** — domaine `ariane.krappo.fr`, fichiers servis par `files.ariane.krappo.fr`.

## 1. Objectif

Application web de stockage et de partage de documents (PDF, docx, pptx, xlsx, images…), un « Google Drive » souverain pour environ **80 formateurs BAFA/BAFD**.

- Consultation et téléchargement **sans compte**, protégés par un **code de partage commun**.
- Toute personne ayant le code peut **proposer** l'ajout, la modification ou la suppression d'un document (en laissant un **nom**, sans notification en retour).
- Chaque proposition doit être **validée par au moins 1 utilisateur certifié** (compte connecté) avant publication.
- Tous les contenus publiés sont publics (pour les détenteurs du code). Pas de données personnelles dans les documents administratifs.
- Pas de versionnage des fichiers. Vidéos autorisées **uniquement sous forme de lien** (YouTube, Vimeo, PeerTube…).

## 2. Stack

| Couche | Choix |
|---|---|
| Backend | Symfony 7.4 LTS (PHP 8.3+), Doctrine ORM 3 / DBAL 4 |
| Interface | Twig + Hotwire (Turbo + Stimulus) + Tailwind CSS 4 (AssetMapper, bundle Symfonycasts). **Pas de SPA.** Dépendances JS vendorisées dans `assets/lib` (aucun CDN). |
| Base de données | PostgreSQL 16 (`unaccent`, `pg_trgm`, config plein texte `ariane_fr` = `french` + `unaccent`) |
| Stockage fichiers | Garage (S3) via Flysystem, buckets privés `quarantaine` et `publie` |
| Extraction de texte | Apache Tika (image `-full`, OCR Tesseract français pour les PDF scannés) |
| Prévisualisation | pdf.js ; docx/pptx/xlsx/odt… → PDF via Gotenberg (sans accès réseau) |
| Antivirus | ClamAV (clamd, commande INSTREAM), échec = refus (fail closed) |
| ZIP groupé | ZipStream-PHP, à la volée depuis S3 |
| Captcha | Altcha v3 (preuve de travail PBKDF2, build « external » compatible CSP) |
| 2FA | `scheb/2fa-bundle` + TOTP, obligatoire pour les certifiés |
| Proxy / sécurité réseau | Caddy (+ bouncer CrowdSec) |
| Déploiement | Docker Compose sur VPS OVH, sauvegardes restic chiffrées vers un second fournisseur |

## 3. Rôles

- **Visiteur** : saisit le code de partage, parcourt, recherche, prévisualise, télécharge (unitaire ou ZIP), propose une modification (nom obligatoire).
- **Certifié** (`ROLE_CERTIFIE`) : compte avec 2FA, valide ou refuse les propositions (avec commentaire, obligatoire pour un refus). **Ne peut pas valider sa propre proposition.**
- **Admin** (`ROLE_ADMIN > ROLE_CERTIFIE`) : approuve les inscriptions, gère le code de partage (rotation), les dossiers, la corbeille, les statistiques, consulte le journal d'audit.

## 4. Modèle de données

`user`, `folder` (arbre, slug unique), `document` (UUID v7, `search_vector` tsvector maintenu par trigger, `tags_text` dénormalisé, statut `publie`/`corbeille`, `deleted_at`, `download_count`), `tag` + `document_tag`, `change_request` (type, document cible, payload JSON, nom et éventuel compte du proposant, statut, validateur, commentaire, clé et taille en quarantaine), `download_stat` (agrégat journalier, **aucune IP**), `audit_log`, `share_code` (hash Argon2id, historique ; la session visiteur ne garde que l'id du code saisi), plus `sessions` (sessions PHP en base) et `messenger_messages`.

## 5. Workflows

- **Proposition** : upload via Symfony (100 Mo max) → contrôle de la signature réelle (libmagic + inspection des conteneurs OOXML/ODF/OLE), liste blanche, taille, quota de quarantaine (5 Go), ClamAV, renommage en UUID, SHA-256 → bucket `quarantaine` → `change_request` en attente. Captcha + 5 propositions/heure/IP pour les visiteurs.
- **Validation** (`ReviewService`, verrou pessimiste) : copie vers `publie`, création/mise à jour du document, suppression de l'objet en quarantaine après commit, extraction Tika et aperçu Gotenberg en asynchrone (Messenger), audit.
- **Modification** : remplacement éventuel du fichier (l'ancien objet est supprimé, pas de versionnage). **Suppression** : corbeille 30 jours puis purge (planificateur Symfony, chaque nuit). Propositions refusées : fichier purgé après 7 jours.
- **Téléchargement** : compteur puis redirection vers une URL S3 présignée de **60 s** (`attachment`) sur `files.` ; aperçus : 5 min.
- **ZIP** : 200 fichiers / 1 Go maximum, 10 archives/heure/IP.
- **Recherche** : `websearch_to_tsquery('ariane_fr')` + `word_similarity` (pg_trgm, seuil 0,5) sur le titre, filtres dossier (sous-arbre) et tag.

## 6. Sécurité (non négociable)

Garage jamais exposé (réseau Docker interne ; Caddy ne relaie que les GET/HEAD présignés) · code de partage haché + limitation (5 essais / 15 min / IP) + session régénérée · CSP stricte à nonce (pas d'`unsafe-inline` pour les scripts), HSTS, `nosniff`, `X-Frame-Options: DENY`, cookies `HttpOnly`/`Secure`/`SameSite=Lax` limités à l'hôte · CSRF sur tous les formulaires (jetons en session) · Argon2id · 2FA obligatoire (redirection forcée vers l'enrôlement) · voters pour toutes les autorisations (`SHARE_ACCESS`, `DOCUMENT_*`, `CHANGE_REQUEST_*`) · Tika et Gotenberg sans accès sortant · journal d'audit · LUKS, pare-feu, restic (voir `docs/deploiement.md`).

## 7. Direction visuelle

Hero en dégradé maillé turquoise → jaune → orange → rouge-orange avec grain, bulles flottantes (formats, tags populaires, désactivées avec `prefers-reduced-motion`), titre très gras, barre de recherche en pilule (bouton rond vert `#00C566`, sélecteur de dossier), chips de suggestions, cartes arrondies (pastille de format, taille, téléchargements), fond crème, texte bleu nuit `#13203F`, gris-violet `#6C6889`, rayons 24–32 px, Plus Jakarta Sans auto-hébergée. Les sous-titres blancs sont posés sur un voile bleu nuit translucide pour respecter le contraste WCAG AA.

## 8. Conventions

- Code et commentaires en **anglais** ; interface en **français** via `translations/*.fr.yaml` (jamais de texte en dur).
- PSR-12 / règles Symfony (PHP-CS-Fixer), PHPStan niveau 8, PHPUnit (unitaires, fonctionnels, test de bout en bout `ValidationWorkflowTest`).
- Migrations Doctrine versionnées, pas de modification manuelle du schéma. Les index GIN sont déclarés dans les entités et créés `USING GIN` par la migration.
- Configuration par variables d'environnement, aucun secret dans le dépôt (`.env.prod.example`).
- Services métier dans `src/Service`, accès aux fichiers derrière `App\Storage\DocumentStorage`.
- Commits en français.

## 9. Décisions prises en l'absence de précision (à confirmer)

1. **Vidéos** : liens uniquement (https), intégrés via `youtube-nocookie.com` / `player.vimeo.com`, simple lien sinon. Aucun upload vidéo, donc pas d'upload direct présigné : tout passe par Symfony (100 Mo).
2. **ZIP en upload** non autorisé (vecteur de malware, pas d'aperçu). **SVG** exclu (scripts embarqués). `xls` non accepté (seuls `xlsx`/`ods` étaient listés).
3. **OCR** : Tesseract intégré à Tika (`-full`) plutôt qu'un conteneur OCRmyPDF séparé (même résultat pour la recherche, un service de moins).
4. **Aperçus** : servis eux aussi par URL présignée sur `files.` (CORS limité à l'origine principale pour pdf.js).
5. **Sessions visiteur** : 30 jours, stockées en base ; la rotation du code révoque immédiatement toutes les sessions visiteur.
6. Les **certifiés connectés** ont accès sans code ; leurs propositions ne demandent pas de captcha et sont rattachées à leur compte (interdiction d'auto-validation). L'admin peut valider comme tout certifié.
7. **Refus** : commentaire obligatoire ; validation : commentaire facultatif.
8. **Passkeys** non implémentées (optionnelles) ; réinitialisation de la 2FA par l'admin.
9. **Création des dossiers** réservée à l'admin ; les tags sont créés à la validation d'une proposition.
10. CSP : `style-src-attr 'unsafe-inline'` toléré (attributs `style` des barres de progression et de pdf.js), aucun script inline sans nonce.
