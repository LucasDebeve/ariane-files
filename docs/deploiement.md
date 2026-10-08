# Déploiement (VPS OVH, Docker Compose)

## 1. Serveur

- Debian 12/13, disque chiffré **LUKS** (déverrouillage à distance via `dropbear-initramfs` si besoin).
- Pare-feu minimal : 80/443 (TCP, + 443/UDP pour HTTP/3) et SSH **par clé uniquement**
  (`PasswordAuthentication no`, `PermitRootLogin prohibit-password`).
- Mises à jour automatiques : `unattended-upgrades`.
- Docker Engine + plugin Compose, `restic`.
- DNS : `ariane.krappo.fr` et `files.ariane.krappo.fr` → IP du VPS.

## 2. Configuration

```bash
git clone … /opt/ariane && cd /opt/ariane
cp .env.prod.example .env.prod && chmod 600 .env.prod   # remplir toutes les valeurs
```

Secrets : `openssl rand -hex 32`. Clé Garage : `S3_KEY=GK$(openssl rand -hex 12)`, `S3_SECRET=$(openssl rand -hex 32)`.
`CROWDSEC_BOUNCER_KEY` : n'importe quelle chaîne aléatoire, elle est enregistrée par le conteneur CrowdSec au démarrage.

## 3. Premier lancement

```bash
docker compose --env-file .env.prod up -d --build
./docker/garage/init.sh                                         # layout + clé + buckets privés
docker compose --env-file .env.prod exec php bin/console doctrine:migrations:migrate -n
docker compose --env-file .env.prod exec php bin/console app:share-code:rotate
docker compose --env-file .env.prod exec php bin/console app:admin:create vous@exemple.fr "Votre nom"
```

## 4. Réseau et isolation

| Service | Réseaux | Exposé |
|---|---|---|
| caddy | edge, backend | 80, 443 |
| php, worker, database, garage, tika, gotenberg | backend (`internal: true`, **aucun accès sortant**) | non |
| clamav, crowdsec | backend + egress (mises à jour des signatures / listes) | non |

Garage n'est jamais publié : le vhost `files.ariane.krappo.fr` ne relaie que les requêtes **GET/HEAD
présignées** (`X-Amz-Signature`) vers les deux buckets, avec `nosniff`, CSP `sandbox` et
`Content-Disposition: attachment` imposé dans la signature. Les cookies de session sont limités à
l'hôte principal (pas d'attribut `Domain`).

## 5. Sauvegardes (restic)

`docker/backup/backup.sh` : dump PostgreSQL + snapshots des métadonnées Garage + blocs de données,
chiffrés et dédupliqués vers un **second fournisseur** (`RESTIC_REPOSITORY`, ex. `s3:https://…`).
Initialisation : `restic init`. Timer systemd :

```ini
# /etc/systemd/system/ariane-backup.service
[Service]
Type=oneshot
ExecStart=/opt/ariane/docker/backup/backup.sh

# /etc/systemd/system/ariane-backup.timer
[Timer]
OnCalendar=*-*-* 02:30
Persistent=true
[Install]
WantedBy=timers.target
```

**Test de restauration mensuel** : `docker/backup/restore-test.sh` restaure le dernier snapshot dans
un répertoire temporaire et recharge le dump dans un PostgreSQL jetable.

## 6. Mise à jour

```bash
git pull && docker compose --env-file .env.prod up -d --build
docker compose --env-file .env.prod exec php bin/console doctrine:migrations:migrate -n
```
