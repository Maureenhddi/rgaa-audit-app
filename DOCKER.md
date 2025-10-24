# Guide Docker - RGAA Audit

Ce guide explique comment déployer et utiliser l'application RGAA Audit avec Docker.

## 📋 Prérequis

- [Docker](https://docs.docker.com/get-docker/) (version 20.10 ou supérieure)
- [Docker Compose](https://docs.docker.com/compose/install/) (version 2.0 ou supérieure)
- 4 GB de RAM minimum
- 10 GB d'espace disque

Vérifiez les versions installées :
```bash
docker --version
docker compose version
```

## 🏗 Architecture Docker

L'application est composée de 3 services :

1. **nginx** : Serveur web (port 8080)
2. **php** : PHP 8.2-FPM + Symfony + Node.js + Playwright
3. **db** : MySQL 8.0

```
┌─────────────┐
│   Nginx     │ :8080
│  (Alpine)   │
└──────┬──────┘
       │
┌──────┴──────┐
│  PHP-FPM    │
│ + Symfony   │
│ + Node.js   │
│ + Playwright│
└──────┬──────┘
       │
┌──────┴──────┐
│   MySQL     │ :3306
│     8.0     │
└─────────────┘
```

## 🚀 Démarrage rapide

### 1. Configuration de l'environnement

```bash
# Copier le fichier de configuration Docker
cp .env.docker .env.docker.local

# Éditer la configuration
nano .env.docker.local
```

**Variables importantes à modifier** :

```env
# Sécurité (IMPORTANT : changez ces valeurs !)
MYSQL_ROOT_PASSWORD=votre_mot_de_passe_root_securise
MYSQL_PASSWORD=votre_mot_de_passe_utilisateur_securise
APP_SECRET=votre_secret_aleatoire_de_32_caracteres_minimum

# Google Gemini API (obligatoire)
GEMINI_API_KEY=votre_cle_api_gemini

# Port HTTP (optionnel, par défaut 8080)
HTTP_PORT=8080
```

### 2. Construire et démarrer les conteneurs

```bash
# Construire les images (première fois uniquement)
docker compose build

# Démarrer tous les services
docker compose up -d

# Voir les logs en temps réel
docker compose logs -f
```

**Attendez environ 2-3 minutes** que tous les services démarrent et que les migrations s'exécutent.

### 3. Vérifier que tout fonctionne

```bash
# Vérifier l'état des conteneurs
docker compose ps

# Tous les services doivent être "Up" (healthy pour db)
```

Accédez à l'application : **http://localhost:8080**

## 📝 Commandes utiles

### Gestion des conteneurs

```bash
# Démarrer les services
docker compose up -d

# Arrêter les services
docker compose stop

# Arrêter et supprimer les conteneurs
docker compose down

# Arrêter et supprimer TOUT (y compris les volumes de données !)
docker compose down -v

# Redémarrer un service spécifique
docker compose restart php

# Voir les logs
docker compose logs -f          # Tous les services
docker compose logs -f php      # Service PHP uniquement
docker compose logs -f nginx    # Service Nginx uniquement
```

### Exécuter des commandes dans les conteneurs

```bash
# Ouvrir un shell dans le conteneur PHP
docker compose exec php bash

# Exécuter une commande Symfony
docker compose exec php php bin/console about
docker compose exec php php bin/console cache:clear

# Exécuter Composer
docker compose exec php composer install
docker compose exec php composer require package/name

# Accéder à MySQL
docker compose exec db mysql -u rgaa_user -p rgaa_audit
```

### Base de données

```bash
# Créer la base de données
docker compose exec php php bin/console doctrine:database:create

# Exécuter les migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Créer une migration
docker compose exec php php bin/console make:migration

# Charger des fixtures (données de test)
docker compose exec php php bin/console doctrine:fixtures:load
```

### Développement

```bash
# Installer les dépendances Node.js
docker compose exec php bash -c "cd audit-scripts && npm install"

# Réinstaller les navigateurs Playwright
docker compose exec php bash -c "cd audit-scripts && npx playwright install chromium"

# Vider le cache Symfony
docker compose exec php php bin/console cache:clear

# Voir les routes disponibles
docker compose exec php php bin/console debug:router
```

## 🔧 Configuration avancée

### Modifier le port HTTP

Éditez `.env.docker.local` :
```env
HTTP_PORT=8000  # Au lieu de 8080
```

Puis redémarrez :
```bash
docker compose down
docker compose up -d
```

### Utiliser PostgreSQL au lieu de MySQL

Modifiez `docker-compose.yml` pour remplacer le service `db` :

```yaml
db:
  image: postgres:15-alpine
  environment:
    POSTGRES_DB: rgaa_audit
    POSTGRES_USER: rgaa_user
    POSTGRES_PASSWORD: ${MYSQL_PASSWORD}
```

Et dans `.env.docker.local` :
```env
DATABASE_URL=postgresql://rgaa_user:password@db:5432/rgaa_audit?serverVersion=15&charset=utf8
```

### Activer HTTPS (production)

Pour la production, utilisez un reverse proxy comme [Traefik](https://traefik.io/) ou [nginx-proxy](https://github.com/nginx-proxy/nginx-proxy) avec Let's Encrypt.

Exemple avec Traefik (à ajouter à `docker-compose.yml`) :

```yaml
services:
  nginx:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.rgaa.rule=Host(`votre-domaine.com`)"
      - "traefik.http.routers.rgaa.tls=true"
      - "traefik.http.routers.rgaa.tls.certresolver=letsencrypt"
```

### Volumes persistants

Les données MySQL sont stockées dans un volume Docker nommé `db_data`.

Pour sauvegarder :
```bash
# Dump de la base
docker compose exec db mysqldump -u rgaa_user -p rgaa_audit > backup.sql

# Ou sauvegarder le volume
docker run --rm -v rgaa-audit-app_db_data:/data -v $(pwd):/backup alpine tar czf /backup/db_backup.tar.gz /data
```

Pour restaurer :
```bash
# Restaurer le dump
docker compose exec -T db mysql -u rgaa_user -p rgaa_audit < backup.sql
```

## 🐛 Dépannage

### Les conteneurs ne démarrent pas

```bash
# Voir les logs détaillés
docker compose logs

# Vérifier l'état
docker compose ps

# Forcer la reconstruction
docker compose build --no-cache
docker compose up -d
```

### Erreur "Port already in use"

Un autre service utilise le port 8080 :

```bash
# Changer le port dans .env.docker.local
HTTP_PORT=8081

# Ou arrêter le service qui utilise le port 8080
sudo lsof -i :8080  # Trouver le processus
```

### Base de données non accessible

```bash
# Vérifier que MySQL est prêt
docker compose exec db mysqladmin ping -h localhost -u root -p

# Recréer la base
docker compose exec php php bin/console doctrine:database:drop --force
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate
```

### Les audits échouent

```bash
# Vérifier les logs PHP
docker compose logs php

# Vérifier que Playwright est installé
docker compose exec php bash -c "cd audit-scripts && npx playwright --version"

# Réinstaller Playwright
docker compose exec php bash -c "cd audit-scripts && npx playwright install --with-deps chromium"
```

### Problèmes de permissions

```bash
# Réparer les permissions (depuis l'hôte)
sudo chown -R $USER:$USER .
chmod -R 775 var/

# Ou depuis le conteneur
docker compose exec php chown -R www-data:www-data var/
```

### Vider complètement et recommencer

```bash
# Arrêter et supprimer TOUT
docker compose down -v

# Supprimer les images
docker compose down --rmi all

# Reconstruire
docker compose build --no-cache
docker compose up -d
```

## 📊 Monitoring et logs

### Voir les logs en direct

```bash
# Tous les services
docker compose logs -f

# Service spécifique
docker compose logs -f php
docker compose logs -f nginx
docker compose logs -f db
```

### Statistiques des conteneurs

```bash
# Utilisation CPU/RAM/Réseau
docker stats

# Espace disque utilisé
docker system df
```

### Logs Symfony

```bash
# Logs de développement
docker compose exec php tail -f var/log/dev.log

# Logs de production
docker compose exec php tail -f var/log/prod.log
```

## 🔒 Sécurité en production

### Checklist de sécurité

- [ ] Changez tous les mots de passe par défaut
- [ ] Générez un `APP_SECRET` unique et aléatoire
- [ ] Configurez `APP_ENV=prod` et `APP_DEBUG=0`
- [ ] Utilisez HTTPS (certificat SSL)
- [ ] Ne publiez pas le port MySQL (supprimez `ports:` dans docker-compose.yml)
- [ ] Activez un firewall
- [ ] Configurez des sauvegardes automatiques
- [ ] Limitez les ressources des conteneurs
- [ ] Utilisez des secrets Docker pour les données sensibles

### Limiter les ressources

Ajoutez dans `docker-compose.yml` :

```yaml
services:
  php:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          memory: 512M
```

## 🚀 Déploiement en production

### 1. Configuration production

Créez `.env.docker.production` :

```env
APP_ENV=prod
APP_DEBUG=0
HTTP_PORT=80
FORCE_MIGRATIONS=0
```

### 2. Construire pour la production

```bash
# Builder avec les optimisations
docker compose -f docker-compose.yml --env-file .env.docker.production build

# Démarrer
docker compose -f docker-compose.yml --env-file .env.docker.production up -d
```

### 3. Optimisations Symfony

```bash
# Optimiser Composer
docker compose exec php composer install --no-dev --optimize-autoloader

# Vider et réchauffer le cache
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console cache:warmup
```

## 📚 Ressources

- [Documentation Docker](https://docs.docker.com/)
- [Documentation Docker Compose](https://docs.docker.com/compose/)
- [Documentation Symfony](https://symfony.com/doc)
- [Best practices Docker](https://docs.docker.com/develop/dev-best-practices/)

## 💡 Commandes de maintenance

```bash
# Nettoyer les images inutilisées
docker system prune -a

# Voir l'espace utilisé
docker system df

# Mettre à jour les images
docker compose pull
docker compose up -d --build

# Backup automatique (à mettre dans un cron)
docker compose exec db mysqldump -u rgaa_user -p${MYSQL_PASSWORD} rgaa_audit | gzip > backup-$(date +%Y%m%d).sql.gz
```

---

**Besoin d'aide ?** Consultez les logs avec `docker compose logs -f` ou créez une issue sur le dépôt Git.
