# Guide d'installation détaillé

Ce guide vous accompagne pas à pas dans l'installation de l'application RGAA Audit.

## Prérequis système

### Logiciels requis

1. **PHP 8.1 ou supérieur**
   ```bash
   php -v
   # Doit afficher : PHP 8.1.x ou supérieur
   ```

2. **Composer** (gestionnaire de dépendances PHP)
   ```bash
   composer --version
   ```
   Si non installé : https://getcomposer.org/download/

3. **Node.js 18+ et npm**
   ```bash
   node -v  # Doit afficher v18.x ou supérieur
   npm -v
   ```
   Si non installé : https://nodejs.org/

4. **MySQL 8.0+ ou PostgreSQL 15+**
   ```bash
   mysql --version
   # ou
   psql --version
   ```

5. **wkhtmltopdf** (pour l'export PDF)
   ```bash
   # Ubuntu/Debian
   sudo apt-get install wkhtmltopdf

   # macOS
   brew install wkhtmltopdf

   # Windows
   # Télécharger depuis : https://wkhtmltopdf.org/downloads.html
   ```

## Installation étape par étape

### Étape 1 : Récupérer le projet

```bash
# Si vous utilisez Git
git clone <url-du-depot> rgaa-audit-app
cd rgaa-audit-app

# Sinon, décompressez l'archive dans le dossier rgaa-audit-app
```

### Étape 2 : Configuration de l'environnement

```bash
# Copier le fichier d'environnement exemple
cp .env.local.example .env.local
```

Éditez `.env.local` et configurez :

```env
###> Base de données ###
# Pour MySQL
DATABASE_URL="mysql://utilisateur:motdepasse@127.0.0.1:3306/rgaa_audit?serverVersion=8.0&charset=utf8mb4"

# Pour PostgreSQL
# DATABASE_URL="postgresql://utilisateur:motdepasse@127.0.0.1:5432/rgaa_audit?serverVersion=15&charset=utf8"

###> Sécurité Symfony ###
# Générez une chaîne aléatoire de 32 caractères minimum
APP_SECRET=VotreSecretAleatoireDe32CaracteresMinimum

###> Google Gemini API ###
# Obtenez votre clé sur : https://makersuite.google.com/app/apikey
GEMINI_API_KEY=votre_cle_api_gemini_ici

###> Chemins Node.js ###
# Chemin absolu vers le dossier audit-scripts
NODE_SCRIPTS_PATH=/chemin/absolu/vers/rgaa-audit-app/audit-scripts
# Commande pour exécuter Node.js (généralement "node")
NODE_EXECUTABLE=node
```

**Important** : Remplacez tous les exemples par vos vraies valeurs !

### Étape 3 : Installer les dépendances PHP

```bash
composer install
```

Cette commande peut prendre quelques minutes.

### Étape 4 : Installer les dépendances Node.js

```bash
cd audit-scripts
npm install
```

Installez les navigateurs Playwright :
```bash
npm run install-browsers
```

Retournez au dossier principal :
```bash
cd ..
```

### Étape 5 : Créer la base de données

```bash
# Créer la base de données
php bin/console doctrine:database:create

# Exécuter les migrations pour créer les tables
php bin/console doctrine:migrations:migrate
```

Répondez `yes` pour confirmer l'exécution des migrations.

### Étape 6 : Vérifier l'installation

```bash
# Vérifier que tout est configuré correctement
php bin/console about
```

Vous devriez voir des informations sur votre environnement Symfony.

### Étape 7 : Lancer le serveur de développement

Option 1 - Avec Symfony CLI (recommandé) :
```bash
# Installer Symfony CLI si nécessaire : https://symfony.com/download
symfony server:start
```

Option 2 - Avec le serveur PHP intégré :
```bash
php -S localhost:8000 -t public/
```

L'application sera accessible sur : **http://localhost:8000**

## Vérification de l'installation

### 1. Accéder à l'application

Ouvrez votre navigateur et allez sur `http://localhost:8000`

Vous devriez voir la page de connexion.

### 2. Créer un compte

1. Cliquez sur "S'inscrire"
2. Remplissez le formulaire :
   - Nom complet
   - Email
   - Mot de passe (minimum 6 caractères)
   - Acceptez les conditions
3. Cliquez sur "S'inscrire"

### 3. Tester un audit

1. Connectez-vous avec vos identifiants
2. Cliquez sur "Nouvel audit"
3. Entrez une URL de test : `https://www.example.com`
4. Cliquez sur "Lancer l'audit"

L'audit devrait se lancer et afficher les résultats après quelques minutes.

## Configuration de la clé API Gemini

### Obtenir une clé API

1. Allez sur [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Connectez-vous avec votre compte Google
3. Cliquez sur "Create API Key"
4. Copiez la clé générée

### Configurer la clé dans l'application

Éditez `.env.local` :
```env
GEMINI_API_KEY=votre_cle_copiee_ici
```

Redémarrez le serveur Symfony pour prendre en compte le changement.

## Problèmes courants

### Erreur : "An exception occurred in driver: SQLSTATE[HY000] [2002]"

**Cause** : La base de données n'est pas accessible.

**Solution** :
1. Vérifiez que MySQL/PostgreSQL est démarré
2. Vérifiez les identifiants dans `.env.local`
3. Testez la connexion :
   ```bash
   mysql -u utilisateur -p
   # ou
   psql -U utilisateur -d postgres
   ```

### Erreur : "node: command not found"

**Cause** : Node.js n'est pas installé ou pas dans le PATH.

**Solution** :
1. Installez Node.js depuis https://nodejs.org/
2. Vérifiez : `node -v`
3. Si nécessaire, ajoutez Node.js au PATH

### Erreur : "Playwright script not found"

**Cause** : Le chemin vers les scripts Node.js est incorrect.

**Solution** :
1. Vérifiez `NODE_SCRIPTS_PATH` dans `.env.local`
2. Utilisez un chemin absolu :
   ```bash
   pwd  # Affiche le chemin actuel
   # Ajoutez /audit-scripts à la fin
   ```

### Erreur : "Failed to launch browser"

**Cause** : Les navigateurs Playwright ne sont pas installés.

**Solution** :
```bash
cd audit-scripts
npm run install-browsers
cd ..
```

### Erreur : "Invalid Gemini API key"

**Cause** : La clé API Gemini est incorrecte ou expirée.

**Solution** :
1. Vérifiez que la clé dans `.env.local` est correcte
2. Générez une nouvelle clé sur https://makersuite.google.com/app/apikey
3. Redémarrez le serveur

## Configuration pour la production

### Passage en mode production

1. Modifiez `.env.local` :
   ```env
   APP_ENV=prod
   APP_DEBUG=0
   ```

2. Videz et réchauffez le cache :
   ```bash
   php bin/console cache:clear
   php bin/console cache:warmup
   ```

3. Utilisez un vrai serveur web (Apache/Nginx)

### Sécurité

- Changez `APP_SECRET` pour une valeur aléatoire unique
- Utilisez HTTPS en production
- Configurez un firewall pour la base de données
- Limitez les permissions des fichiers :
  ```bash
  chmod -R 755 public/
  chmod -R 775 var/
  ```

### Performance

- Activez l'OPcache PHP
- Configurez un cache Redis/Memcached
- Optimisez Composer :
  ```bash
  composer install --no-dev --optimize-autoloader
  ```

## Support

Si vous rencontrez des problèmes non couverts dans ce guide :

1. Vérifiez les logs Symfony : `var/log/dev.log`
2. Vérifiez les logs Node.js dans la console
3. Consultez la documentation Symfony : https://symfony.com/doc
4. Créez une issue sur le dépôt Git

---

**Félicitations !** 🎉 Votre application RGAA Audit est maintenant installée et prête à l'emploi.
