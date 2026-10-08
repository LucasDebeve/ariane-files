# Déploiement (VPS OVH, Docker Compose)

## 1. Serveur

- Debian 12/13, disque chiffré **LUKS** (déverrouillage à distance via `dropbear-initramfs` si besoin).
- Pare-feu minimal : 80/443 (TCP, + 443/UDP pour HTTP/3) et SSH **par clé uniquement**
  (`PasswordAuthentication no`, `PermitRootLogin prohibit-password`).
- Mises à jour automatiques : `unattended-upgrades`.
- Docker Engine + plugin Compose, `restic`.
- DNS : `ariane.lucasdebeve.eu` et `files.ariane.lucasdebeve.eu` → IP du VPS.
- Le reverse proxy est le **Caddy partagé du VPS** (`~/configuration/caddy`) : la stack Ariane n'en
  embarque pas et ne publie aucun port. Les deux vhosts sont décrits dans
  `~/configuration/caddy/conf/sites/ariane.caddy`.

## 2. Configuration

```bash
git clone … /opt/ariane && cd /opt/ariane
cp .env.prod.example .env.prod && chmod 600 .env.prod   # remplir toutes les valeurs
```

Secrets : `openssl rand -hex 32`. Clé Garage : `S3_KEY=GK$(openssl rand -hex 12)`, `S3_SECRET=$(openssl rand -hex 32)`.

## 3. Premier lancement

```bash
make -C ~/configuration up STACK=caddy                          # réseau `proxy` + volume `ariane_public`
docker compose --env-file .env.prod up -d --build
./docker/garage/init.sh                                         # layout + clé + buckets privés
docker compose --env-file .env.prod exec php bin/console doctrine:migrations:migrate -n
docker compose --env-file .env.prod exec php bin/console app:share-code:rotate
docker compose --env-file .env.prod exec php bin/console app:admin:create vous@exemple.fr "Votre nom"
```

## 4. Réseau et isolation

| Service | Réseaux | Exposé |
|---|---|---|
| php, garage | backend + proxy (réseau du Caddy partagé, alias `ariane-php` / `ariane-garage`) | via Caddy |
| worker, database, tika, gotenberg | backend (`internal: true`, **aucun accès sortant**) | non |
| clamav | backend + egress (mises à jour des signatures) | non |
| assets | aucun (tâche ponctuelle : copie `public/` dans le volume `ariane_public`) | non |

Caddy sert les fichiers statiques depuis le volume `ariane_public` (monté en lecture seule) et
transmet le PHP à `ariane-php:9000` en FastCGI. Le volume est resynchronisé à chaque
`docker compose up` par le service `assets`.

Garage n'est jamais publié sur l'hôte : le vhost `files.ariane.lucasdebeve.eu` ne relaie que les requêtes **GET/HEAD
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
