# Docker Configuration - RGAA Audit

Configuration Docker pour l'application RGAA Audit.

## 📁 Structure

```
docker/
├── nginx/
│   ├── nginx.conf        # Configuration globale Nginx
│   └── default.conf      # Virtual host pour Symfony
└── php/
    ├── Dockerfile        # Image PHP 8.2 + Node.js + Playwright
    ├── php.ini           # Configuration PHP optimisée
    └── docker-entrypoint.sh # Script de démarrage
```

## 🐳 Services Docker

### nginx (Port 8080)
- **Image** : nginx:alpine
- **Rôle** : Serveur web, reverse proxy vers PHP-FPM
- **Config** : nginx.conf + default.conf
- **Optimisations** :
  - Gzip compression
  - Cache des fichiers statiques
  - Timeouts adaptés pour audits longs

### php (Port 9000 interne)
- **Image** : Custom (PHP 8.2-FPM)
- **Contenu** :
  - PHP 8.2 avec extensions (pdo, mysql, intl, gd, zip, opcache)
  - Composer
  - Node.js 20 + npm
  - Playwright + Chromium
  - wkhtmltopdf
- **Volumes** : Code source monté en /var/www/html
- **Optimisations** :
  - OPcache activé
  - Realpath cache
  - Memory limit 512M
  - Max execution time 600s (pour audits)

### db (Port 3306)
- **Image** : mysql:8.0
- **Volume** : db_data (persistant)
- **Healthcheck** : mysqladmin ping

## 📝 Fichiers de configuration

### nginx/nginx.conf
Configuration globale Nginx :
- Worker processes auto
- Gzip compression
- Keepalive timeout
- Client max body size 20M

### nginx/default.conf
Virtual host Symfony :
- Root : /var/www/html/public
- FastCGI vers php:9000
- Réécriture pour index.php
- Cache des assets
- Sécurité (blocage .git, .env, etc.)

### php/Dockerfile
Multi-stage build :
1. Installation des dépendances système
2. Installation des extensions PHP
3. Installation Composer
4. Installation Node.js 20
5. Installation Playwright + browsers
6. Configuration utilisateur www-data
7. Copie du code
8. Installation des dépendances (composer + npm)

### php/php.ini
Configuration PHP optimisée :
- `memory_limit = 512M`
- `max_execution_time = 600`
- `upload_max_filesize = 20M`
- `opcache.enable = 1`
- `opcache.memory_consumption = 256`
- Timezone : Europe/Paris
- Error logging activé

### php/docker-entrypoint.sh
Script de démarrage :
1. Attente de la base de données
2. Exécution des migrations Doctrine
3. Vidage et réchauffage du cache
4. Installation des assets
5. Démarrage PHP-FPM

## 🚀 Utilisation

### Construire l'image

```bash
docker compose build
```

### Démarrer les services

```bash
docker compose up -d
```

### Voir les logs

```bash
docker compose logs -f
docker compose logs -f php    # PHP uniquement
docker compose logs -f nginx  # Nginx uniquement
```

### Accéder au conteneur

```bash
docker compose exec php bash
```

### Exécuter des commandes Symfony

```bash
docker compose exec php php bin/console about
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console doctrine:migrations:migrate
```

## 🔧 Personnalisation

### Changer la version PHP

Dans `docker-compose.yml` :
```yaml
services:
  php:
    build:
      args:
        - PHP_VERSION=8.3  # Au lieu de 8.2
```

### Ajouter une extension PHP

Dans `php/Dockerfile`, après les extensions existantes :
```dockerfile
RUN docker-php-ext-install nom_extension
```

### Modifier la configuration PHP

Éditez `php/php.ini` et redémarrez :
```bash
docker compose restart php
```

### Modifier la configuration Nginx

Éditez `nginx/default.conf` et redémarrez :
```bash
docker compose restart nginx
```

## 📊 Volumes

### Volume de données MySQL

```bash
# Voir le volume
docker volume ls | grep rgaa

# Inspecter le volume
docker volume inspect rgaa-audit-app_db_data

# Sauvegarder le volume
docker run --rm -v rgaa-audit-app_db_data:/data -v $(pwd):/backup alpine tar czf /backup/db_backup.tar.gz /data

# Restaurer le volume
docker run --rm -v rgaa-audit-app_db_data:/data -v $(pwd):/backup alpine tar xzf /backup/db_backup.tar.gz -C /
```

## 🐛 Dépannage

### Les conteneurs ne démarrent pas

```bash
# Voir les logs détaillés
docker compose logs

# Reconstruire sans cache
docker compose build --no-cache
```

### Erreur de permissions

```bash
# Depuis l'hôte
sudo chown -R $USER:$USER .

# Depuis le conteneur
docker compose exec php chown -R www-data:www-data var/
```

### Base de données non accessible

```bash
# Vérifier le healthcheck
docker compose ps

# Tester la connexion
docker compose exec db mysqladmin ping -h localhost -u root -p
```

### Playwright ne fonctionne pas

```bash
# Réinstaller les navigateurs
docker compose exec php bash -c "cd audit-scripts && npx playwright install --with-deps chromium"
```

## 📚 Ressources

- [Documentation Docker](https://docs.docker.com/)
- [Documentation Docker Compose](https://docs.docker.com/compose/)
- [Nginx best practices](https://www.nginx.com/blog/nginx-caching-guide/)
- [PHP-FPM optimization](https://www.php.net/manual/en/install.fpm.php)

## 🔒 Sécurité

### Recommandations

1. **Changez tous les mots de passe** dans `.env.docker.local`
2. **Utilisez des secrets Docker** en production
3. **Ne publiez pas le port MySQL** (commentez `ports:` dans docker-compose.yml)
4. **Activez HTTPS** avec un reverse proxy (Traefik, nginx-proxy)
5. **Limitez les ressources** des conteneurs (CPU, RAM)
6. **Scannez les vulnérabilités** : `docker scan rgaa-audit-app-php`

### Production

Pour la production, créez un `docker-compose.prod.yml` :

```yaml
version: '3.8'

services:
  nginx:
    restart: always

  php:
    restart: always
    environment:
      APP_ENV: prod
      APP_DEBUG: 0
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G

  db:
    restart: always
    # Ne pas publier le port en production
    # ports: []
```

Utilisation :
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

---

Pour plus d'informations, consultez [DOCKER.md](../DOCKER.md) à la racine du projet.
