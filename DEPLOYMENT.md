# Guide de déploiement en production

Ce guide explique comment déployer l'application RGAA Audit App sur un serveur de production.

## 📋 Table des matières

- [Choix de l'hébergement](#choix-de-lhébergement)
- [Prérequis serveur](#prérequis-serveur)
- [Déploiement sur VPS (Option 1)](#déploiement-sur-vps-option-1)
- [Déploiement sur cloud (Option 2)](#déploiement-sur-cloud-option-2)
- [Configuration SSL/HTTPS](#configuration-sslhttps)
- [Sauvegarde et monitoring](#sauvegarde-et-monitoring)
- [Maintenance](#maintenance)

---

## 🌐 Choix de l'hébergement

### Option 1 : VPS (Virtual Private Server) ⭐ Recommandé

**Avantages :**
- Contrôle total sur le serveur
- Meilleur rapport qualité/prix
- Flexibilité maximale
- Données en France (RGPD)

**Providers recommandés :**

| Provider | Prix/mois | Specs | Localisation |
|----------|-----------|-------|--------------|
| **OVH** | 7€ | 2 vCPU, 4GB RAM, 80GB SSD | France 🇫🇷 |
| **Scaleway** | 6€ | 2 vCPU, 4GB RAM, 40GB SSD | France 🇫🇷 |
| **Hetzner** | 5€ | 2 vCPU, 4GB RAM, 40GB SSD | Allemagne 🇩🇪 |
| **DigitalOcean** | 6$ | 2 vCPU, 4GB RAM, 80GB SSD | USA/EU |

### Option 2 : Cloud Platform (PaaS)

**Avantages :**
- Déploiement automatisé
- Scaling automatique
- Moins de maintenance

**Providers :**
- **Platform.sh** (spécialisé Symfony) - ~30€/mois
- **Heroku** - ~7-25$/mois
- **AWS Elastic Beanstalk** - variable
- **Google Cloud Run** - variable

### Option 3 : Mutualisé (Non recommandé)

❌ **Pas adapté** pour cette application car :
- Nécessite Node.js + Playwright
- Nécessite Docker
- Consommation mémoire importante

---

## 📦 Prérequis serveur

### Système d'exploitation
- **Ubuntu 22.04 LTS** (recommandé)
- Debian 11+
- CentOS 8+

### Spécifications minimales
- **CPU :** 2 cores
- **RAM :** 4 GB (6 GB recommandé)
- **Disque :** 50 GB SSD
- **Bande passante :** Illimitée ou 1 TB/mois minimum

### Logiciels requis
- Docker 20.10+
- Docker Compose 2.0+
- Git
- Certbot (pour SSL)

---

## 🚀 Déploiement sur VPS (Option 1)

### Étape 1 : Préparation du serveur

```bash
# Connexion SSH
ssh root@votre-ip-serveur

# Mise à jour du système
apt update && apt upgrade -y

# Installation des dépendances
apt install -y curl git ufw fail2ban

# Configuration pare-feu
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP
ufw allow 443/tcp  # HTTPS
ufw enable
```

### Étape 2 : Installation Docker

```bash
# Installation Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Installation Docker Compose
curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose

# Vérification
docker --version
docker-compose --version
```

### Étape 3 : Clonage du projet

```bash
# Créer un utilisateur dédié (recommandé)
adduser rgaa
usermod -aG docker rgaa
su - rgaa

# Cloner le projet
cd /home/rgaa
git clone git@github.com:Maureenhddi/rgaa-audit-app.git
cd rgaa-audit-app
```

### Étape 4 : Configuration de production

```bash
# Copier le fichier d'environnement
cp .env.local.example .env.local
nano .env.local
```

**Configuration `.env.local` production :**

```env
###> Symfony Framework ###
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=GENEREZ_UN_SECRET_ALEATOIRE_64_CARACTERES_ICI
###< Symfony Framework ###

###> Base de données ###
DATABASE_URL="mysql://rgaa_user:MOT_DE_PASSE_SECURISE_ICI@db:3306/rgaa_audit?serverVersion=8.0&charset=utf8mb4"
###< Base de données ###

###> Google Gemini API ###
GEMINI_API_KEY=VOTRE_CLE_API_GEMINI
GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent
###< Google Gemini API ###

###> Scripts Node.js ###
NODE_SCRIPTS_PATH=/var/www/html/audit-scripts
NODE_EXECUTABLE=node
###< Scripts Node.js ###

###> Production URLs ###
APP_URL=https://votredomaine.com
TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
TRUSTED_HOSTS='^votredomaine\.com$'
###< Production URLs ###
```

**Créer `.env.docker.prod` pour les mots de passe MySQL :**

```bash
nano .env.docker.prod
```

```env
DB_ROOT_PASSWORD=MOT_DE_PASSE_ROOT_MYSQL_TRES_SECURISE
DB_PASSWORD=MOT_DE_PASSE_USER_MYSQL_SECURISE
```

### Étape 5 : Construction et démarrage

```bash
# Construire les images
docker-compose -f docker-compose.prod.yml build

# Démarrer les conteneurs
docker-compose -f docker-compose.prod.yml up -d

# Installer les dépendances PHP
docker-compose -f docker-compose.prod.yml exec php composer install --no-dev --optimize-autoloader

# Installer dépendances Node.js
docker-compose -f docker-compose.prod.yml exec php bash -c "cd /var/www/html/audit-scripts && npm ci --production"

# Installer Playwright
docker-compose -f docker-compose.prod.yml exec php bash -c "cd /var/www/html/audit-scripts && npx playwright install chromium --with-deps"

# Créer la base de données
docker-compose -f docker-compose.prod.yml exec php php bin/console doctrine:database:create --if-not-exists

# Exécuter les migrations
docker-compose -f docker-compose.prod.yml exec php php bin/console doctrine:migrations:migrate --no-interaction

# Vider le cache
docker-compose -f docker-compose.prod.yml exec php php bin/console cache:clear --env=prod

# Warmup du cache
docker-compose -f docker-compose.prod.yml exec php php bin/console cache:warmup --env=prod
```

### Étape 6 : Configuration du nom de domaine

**Chez votre registrar (OVH, Gandi, etc.) :**

```
Type    Nom                 Valeur
A       votredomaine.com    XX.XX.XX.XX (IP de votre serveur)
A       www                 XX.XX.XX.XX (IP de votre serveur)
```

**Temps de propagation DNS :** 1-24 heures

---

## 🔐 Configuration SSL/HTTPS

### Installation Certbot (Let's Encrypt - Gratuit)

```bash
# Installation Certbot
apt install -y certbot python3-certbot-nginx

# Arrêter temporairement Nginx
docker-compose -f docker-compose.prod.yml stop web

# Obtenir le certificat SSL
certbot certonly --standalone -d votredomaine.com -d www.votredomaine.com --email votre@email.com --agree-tos --non-interactive

# Redémarrer Nginx
docker-compose -f docker-compose.prod.yml start web
```

### Configuration Nginx pour HTTPS

Créer `/home/rgaa/rgaa-audit-app/infra/docker/nginx/prod.conf` :

```nginx
server {
    listen 80;
    server_name votredomaine.com www.votredomaine.com;

    # Redirection HTTP vers HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name votredomaine.com www.votredomaine.com;

    root /var/www/html/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/votredomaine.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/votredomaine.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Logs
    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

**Redémarrer Nginx :**

```bash
docker-compose -f docker-compose.prod.yml restart web
```

### Renouvellement automatique SSL

```bash
# Créer un cron job
crontab -e

# Ajouter cette ligne (renouvellement tous les lundis à 3h du matin)
0 3 * * 1 certbot renew --quiet && docker-compose -f /home/rgaa/rgaa-audit-app/docker-compose.prod.yml restart web
```

---

## 📊 Sauvegarde et monitoring

### Sauvegarde automatique de la base de données

Créer `/home/rgaa/backup.sh` :

```bash
#!/bin/bash

# Configuration
BACKUP_DIR="/home/rgaa/backups"
DATE=$(date +%Y-%m-%d_%H-%M-%S)
CONTAINER="rgaa_db_prod"

# Créer le dossier de backup
mkdir -p $BACKUP_DIR

# Dump MySQL
docker exec $CONTAINER mysqldump -uroot -p${DB_ROOT_PASSWORD} rgaa_audit > $BACKUP_DIR/rgaa_audit_$DATE.sql

# Compresser
gzip $BACKUP_DIR/rgaa_audit_$DATE.sql

# Supprimer les backups de plus de 30 jours
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete

echo "Backup créé : rgaa_audit_$DATE.sql.gz"
```

**Rendre exécutable et planifier :**

```bash
chmod +x /home/rgaa/backup.sh

# Cron job : backup tous les jours à 2h du matin
crontab -e
0 2 * * * /home/rgaa/backup.sh >> /home/rgaa/backup.log 2>&1
```

### Monitoring avec logs

```bash
# Voir les logs en temps réel
docker-compose -f docker-compose.prod.yml logs -f

# Voir uniquement les logs PHP
docker-compose -f docker-compose.prod.yml logs -f php

# Voir les logs Nginx
docker-compose -f docker-compose.prod.yml logs -f web

# Logs Symfony
tail -f var/log/prod.log
```

### Monitoring des ressources

```bash
# Installer htop
apt install htop

# Voir l'utilisation des ressources
htop

# Voir l'utilisation Docker
docker stats
```

---

## 🔄 Déploiement continu (CI/CD)

### Script de déploiement automatique

Créer `/home/rgaa/deploy.sh` :

```bash
#!/bin/bash

cd /home/rgaa/rgaa-audit-app

# Récupérer les dernières modifications
git pull origin main

# Rebuild si nécessaire
docker-compose -f docker-compose.prod.yml build

# Redémarrer les services
docker-compose -f docker-compose.prod.yml up -d

# Installer les dépendances
docker-compose -f docker-compose.prod.yml exec -T php composer install --no-dev --optimize-autoloader

# Migrations
docker-compose -f docker-compose.prod.yml exec -T php php bin/console doctrine:migrations:migrate --no-interaction

# Vider le cache
docker-compose -f docker-compose.prod.yml exec -T php php bin/console cache:clear --env=prod

echo "Déploiement terminé !"
```

**Utilisation :**

```bash
chmod +x /home/rgaa/deploy.sh
/home/rgaa/deploy.sh
```

---

## 🛡️ Sécurité

### Fail2ban (protection contre brute-force)

```bash
# Installation
apt install fail2ban

# Configuration
nano /etc/fail2ban/jail.local
```

```ini
[sshd]
enabled = true
port = 22
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
bantime = 3600
```

```bash
systemctl restart fail2ban
```

### Mise à jour de sécurité automatique

```bash
# Installer unattended-upgrades
apt install unattended-upgrades

# Activer
dpkg-reconfigure -plow unattended-upgrades
```

---

## 🔧 Maintenance

### Mise à jour de l'application

```bash
cd /home/rgaa/rgaa-audit-app
git pull origin main
/home/rgaa/deploy.sh
```

### Nettoyage de l'espace disque

```bash
# Nettoyer les images Docker inutilisées
docker system prune -a

# Nettoyer les logs Symfony
docker-compose -f docker-compose.prod.yml exec php rm -rf var/log/*

# Nettoyer le cache
docker-compose -f docker-compose.prod.yml exec php php bin/console cache:clear --env=prod
```

### Redémarrage complet

```bash
cd /home/rgaa/rgaa-audit-app
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d
```

---

## 📊 Monitoring avancé (Optionnel)

### Option 1 : Sentry (monitoring erreurs)

1. Créer un compte sur [sentry.io](https://sentry.io)
2. Installer le bundle :

```bash
composer require sentry/sentry-symfony
```

3. Configurer dans `.env.local` :

```env
SENTRY_DSN=https://xxxx@sentry.io/xxxxx
```

### Option 2 : New Relic (APM)

Monitoring de performance complet - Essai gratuit puis payant.

### Option 3 : Uptime Robot

Monitoring de disponibilité gratuit :
- [uptimerobot.com](https://uptimerobot.com)
- Vérifie que le site est accessible
- Notifications email/SMS

---

## ✅ Checklist finale

Avant de mettre en production :

- [ ] `.env.local` configuré avec `APP_ENV=prod` et `APP_DEBUG=0`
- [ ] `APP_SECRET` généré aléatoirement (64+ caractères)
- [ ] Mots de passe MySQL sécurisés
- [ ] Clé API Gemini configurée
- [ ] DNS pointant vers le serveur
- [ ] SSL/HTTPS configuré et fonctionnel
- [ ] Pare-feu (UFW) activé
- [ ] Fail2ban configuré
- [ ] Backups automatiques planifiés
- [ ] Monitoring configuré (logs, uptime)
- [ ] Tests effectués en production
- [ ] Documentation mise à jour

---

## 🆘 Support

En cas de problème :

1. Vérifier les logs : `docker-compose logs -f`
2. Vérifier l'état des conteneurs : `docker-compose ps`
3. Vérifier la configuration DNS
4. Vérifier les certificats SSL : `certbot certificates`
5. Consulter la documentation Symfony
6. Créer une issue sur GitHub

---

**Temps estimé de déploiement complet :** 2-3 heures

**Coût mensuel estimé :** 5-10€ (VPS) + 0€ (SSL gratuit)
