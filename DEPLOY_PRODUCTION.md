# Guide de Déploiement en Production avec Traefik

Ce guide explique comment déployer l'application RGAA Audit en production avec Traefik comme reverse proxy.

## Prérequis

1. **Serveur de production** avec Docker et Docker Compose installés
2. **Traefik** déjà configuré et en cours d'exécution sur le serveur
3. **Nom de domaine** pointant vers votre serveur
4. **Réseau Docker Traefik** créé (par défaut : `traefik_network`)

## Vérification de Traefik

Assurez-vous que Traefik est bien configuré avec un réseau externe :

```bash
# Vérifier que le réseau traefik_network existe
docker network ls | grep traefik_network

# Si le réseau n'existe pas, le créer
docker network create traefik_network
```

## Configuration de l'environnement

### 1. Copier le fichier d'environnement

```bash
cp .env.docker.production .env.docker.production.local
```

### 2. Modifier `.env.docker.production.local` avec vos valeurs

```bash
nano .env.docker.production.local
```

**Variables obligatoires à modifier :**

```env
# Votre domaine
DOMAIN=rgaa-audit.votredomaine.fr

# Mots de passe MySQL (générez des mots de passe forts)
MYSQL_ROOT_PASSWORD=un_mot_de_passe_root_tres_securise
MYSQL_PASSWORD=un_mot_de_passe_utilisateur_securise

# Secret Symfony (générez avec: php -r "echo bin2hex(random_bytes(32));")
APP_SECRET=votre_secret_aleatoire_64_caracteres_hexadecimaux

# Votre clé API Gemini
GEMINI_API_KEY=votre_cle_api_gemini
```

### 3. Générer un secret Symfony sécurisé

```bash
# Avec PHP
php -r "echo bin2hex(random_bytes(32));"

# Ou avec OpenSSL
openssl rand -hex 32
```

## Déploiement

### 1. Cloner ou mettre à jour le dépôt

```bash
git clone https://github.com/votre-utilisateur/rgaa-audit-app.git
cd rgaa-audit-app
```

### 2. Construire et démarrer les conteneurs

```bash
# Charger les variables d'environnement
export $(cat .env.docker.production.local | xargs)

# Démarrer les services
docker-compose -f docker-compose.prod.yml --env-file .env.docker.production.local up -d --build
```

### 3. Vérifier le statut

```bash
docker-compose -f docker-compose.prod.yml ps
```

### 4. Vérifier les logs

```bash
# Tous les logs
docker-compose -f docker-compose.prod.yml logs -f

# Logs spécifiques
docker-compose -f docker-compose.prod.yml logs -f web
docker-compose -f docker-compose.prod.yml logs -f php
docker-compose -f docker-compose.prod.yml logs -f db
```

## Configuration Traefik

Le fichier `docker-compose.prod.yml` est configuré avec les labels Traefik suivants :

- **HTTP → HTTPS redirect** : Tout le trafic HTTP est redirigé vers HTTPS
- **Certificat SSL automatique** : Let's Encrypt via le resolver `letsencrypt`
- **Routage** : Basé sur le domaine défini dans `DOMAIN`

### Exemple de configuration Traefik minimale

Votre Traefik doit avoir un `docker-compose.yml` similaire à :

```yaml
version: '3.8'

services:
  traefik:
    image: traefik:v2.10
    container_name: traefik
    restart: always
    ports:
      - "80:80"
      - "443:443"
    networks:
      - traefik_network
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./traefik.yml:/traefik.yml:ro
      - ./acme.json:/acme.json
    labels:
      - "traefik.enable=true"

networks:
  traefik_network:
    external: true
```

Et un fichier `traefik.yml` :

```yaml
api:
  dashboard: true

entryPoints:
  web:
    address: ":80"
  websecure:
    address: ":443"

providers:
  docker:
    endpoint: "unix:///var/run/docker.sock"
    exposedByDefault: false
    network: traefik_network

certificatesResolvers:
  letsencrypt:
    acme:
      email: votre-email@example.com
      storage: /acme.json
      httpChallenge:
        entryPoint: web
```

## Migrations de base de données

Les migrations peuvent être exécutées automatiquement au démarrage si `FORCE_MIGRATIONS=1` dans votre `.env.docker.production.local`.

Pour les exécuter manuellement :

```bash
docker exec rgaa_php_prod php bin/console doctrine:migrations:migrate --no-interaction
```

## Commandes utiles

### Redémarrer les services

```bash
docker-compose -f docker-compose.prod.yml restart
```

### Mettre à jour l'application

```bash
# 1. Récupérer les dernières modifications
git pull origin main

# 2. Reconstruire et redémarrer
docker-compose -f docker-compose.prod.yml up -d --build

# 3. Exécuter les migrations si nécessaire
docker exec rgaa_php_prod php bin/console doctrine:migrations:migrate --no-interaction

# 4. Vider le cache
docker exec rgaa_php_prod php bin/console cache:clear --env=prod
```

### Arrêter les services

```bash
docker-compose -f docker-compose.prod.yml down
```

### Sauvegarder la base de données

```bash
docker exec rgaa_db_prod mysqldump -u rgaa_user -p${MYSQL_PASSWORD} rgaa_audit > backup-$(date +%Y%m%d-%H%M%S).sql
```

### Restaurer une sauvegarde

```bash
docker exec -i rgaa_db_prod mysql -u rgaa_user -p${MYSQL_PASSWORD} rgaa_audit < backup-20250101-120000.sql
```

## Résolution de problèmes

### Le site n'est pas accessible

1. Vérifier que les conteneurs sont en cours d'exécution :
   ```bash
   docker-compose -f docker-compose.prod.yml ps
   ```

2. Vérifier les logs de Traefik :
   ```bash
   docker logs traefik
   ```

3. Vérifier que le réseau `traefik_network` est bien connecté :
   ```bash
   docker network inspect traefik_network
   ```

### Erreur de certificat SSL

1. Vérifier les logs Traefik pour les erreurs ACME
2. S'assurer que le port 80 est accessible depuis Internet
3. Vérifier que le domaine pointe bien vers votre serveur

### Erreur de connexion à la base de données

1. Vérifier que le conteneur MySQL est en cours d'exécution
2. Vérifier les identifiants dans `.env.docker.production.local`
3. Consulter les logs :
   ```bash
   docker-compose -f docker-compose.prod.yml logs db
   ```

## Sécurité

- ✅ **Ne jamais** commiter `.env.docker.production.local` (contient vos secrets)
- ✅ Utiliser des mots de passe forts
- ✅ Sauvegarder régulièrement la base de données
- ✅ Mettre à jour régulièrement les images Docker
- ✅ Surveiller les logs pour détecter les anomalies

## Support

Pour toute question ou problème, consultez :
- Documentation Symfony : https://symfony.com/doc
- Documentation Traefik : https://doc.traefik.io/traefik/
- Issues GitHub : https://github.com/votre-utilisateur/rgaa-audit-app/issues
