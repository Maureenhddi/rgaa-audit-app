# RGAA Audit App 🚀

> Application web d'audit d'accessibilité RGAA 4.1 automatisée avec analyse IA

Application Symfony pour auditer l'accessibilité des sites web selon le référentiel RGAA (Référentiel Général d'Amélioration de l'Accessibilité), avec analyse automatique via Playwright, Axe-core, HTML_CodeSniffer et enrichissement intelligent via Google Gemini 2.5 Flash.

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-6.4-000000?style=flat&logo=symfony)](https://symfony.com/)
[![Node.js](https://img.shields.io/badge/Node.js-18+-339933?style=flat&logo=node.js)](https://nodejs.org/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat&logo=docker)](https://www.docker.com/)

---

## 📋 Table des matières

- [Fonctionnalités](#-fonctionnalités)
- [Stack technique](#-stack-technique)
- [Prérequis](#-prérequis)
- [Installation rapide](#-installation-rapide-docker)
- [Configuration](#-configuration)
- [Utilisation](#-utilisation)
- [Architecture](#-architecture)
- [Processus d'audit](#-processus-daudit)
- [Documentation](#-documentation)
- [Contribution](#-contribution)
- [Licence](#-licence)

---

## ✨ Fonctionnalités

### 🔍 Audit automatique multi-sources
- **Playwright** : Tests d'interactivité, navigation au clavier, gestion du focus
- **Axe-core** : Analyse complète WCAG 2.1 AA/AAA
- **HTML_CodeSniffer** : Vérification HTML/CSS et standards RGAA

### 🤖 Analyse IA avec Google Gemini 2.5 Flash
- Enrichissement contextuel des résultats
- Recommandations personnalisées de correction
- Exemples de code pour résoudre les problèmes
- Évaluation de l'impact utilisateur
- Mapping automatique WCAG → RGAA

### 📊 Grille RGAA complète
- **106 critères RGAA 4.1** organisés en 13 thématiques
- Statut pour chaque critère : Conforme / Non-conforme / Non applicable
- Taux de conformité global en pourcentage
- Visualisation par thème avec icônes et couleurs

### ✅ Vérifications manuelles
- Interface de checklist pour les critères non automatisables
- Sauvegarde automatique des vérifications
- Intégration dans le calcul du taux de conformité
- Suivi de progression

### 📈 Résultats détaillés
- Organisation par criticité : **Critique** 🔴 / **Majeur** 🟠 / **Mineur** 🟡
- Affichage par thème RGAA avec design moderne
- Interface à onglets (Vue d'ensemble / Grille RGAA / Détail des problèmes)
- Mode sombre pour le confort visuel

### 📄 Export PDF professionnel
- Rapport complet avec graphiques
- Statistiques détaillées
- Toutes les recommandations et exemples de code
- Formatage print-ready

### 📊 Historique et comparaison
- Liste de tous les audits effectués
- Comparaison avant/après entre deux audits
- Dashboard avec évolution de la conformité
- Statistiques par période

### 🔐 Sécurité et multi-utilisateurs
- Système d'authentification sécurisé
- Isolation des audits par utilisateur
- Voter pattern pour contrôle d'accès
- Hashage bcrypt des mots de passe

---

## 🛠 Stack technique

### Backend
- **PHP 8.2+** avec orienté objet moderne
- **Symfony 6.4** (MVC, Doctrine ORM, Twig, Security)
- **MySQL 8.0** pour le stockage des données
- **Doctrine Migrations** pour la gestion du schéma

### Frontend
- **Twig** templating engine
- **Bootstrap 5** pour le design responsive
- **Chart.js** pour les graphiques
- **JavaScript vanilla** pour l'interactivité
- **Mode sombre** avec persistance localStorage

### Audit automatique
- **Node.js 18+** pour les scripts d'audit
- **Playwright** (tests navigateur automatisés)
- **Axe-core** (moteur d'accessibilité Deque)
- **HTML_CodeSniffer** (validation WCAG/RGAA)

### Intelligence Artificielle
- **Google Gemini 2.5 Flash** (via API REST)
- Analyse contextuelle des erreurs
- Génération de recommandations
- Mapping WCAG/RGAA automatique

### Export & reporting
- **KnpSnappyBundle** (génération PDF)
- **wkhtmltopdf** (rendu HTML→PDF)

### DevOps
- **Docker & Docker Compose** pour environnement isolé
- **Nginx** serveur web
- **PHP-FPM** pour performance
- **Git** pour versionnement

---

## 📦 Prérequis

### Avec Docker (recommandé)
- [Docker](https://docs.docker.com/get-docker/) 20.10+
- [Docker Compose](https://docs.docker.com/compose/install/) 2.0+
- 4 GB RAM minimum
- 10 GB d'espace disque

### Sans Docker
- PHP 8.2+ avec extensions : `pdo_mysql`, `intl`, `mbstring`, `xml`, `curl`
- Composer 2.0+
- Node.js 18+ et npm
- MySQL 8.0+ ou MariaDB 10.6+
- wkhtmltopdf (pour export PDF)

### Configuration requise
- **Clé API Google Gemini** (gratuite) : [Obtenir une clé](https://makersuite.google.com/app/apikey)

---

## 🚀 Installation rapide (Docker)

> **Recommandation :** Docker simplifie considérablement l'installation en isolant toutes les dépendances dans des conteneurs. C'est la méthode recommandée pour démarrer rapidement.

### Prérequis Docker

Avant de commencer, assurez-vous d'avoir :
- **Docker Desktop** installé et démarré ([Télécharger](https://www.docker.com/products/docker-desktop))
- **Git** pour cloner le projet
- **Une clé API Google Gemini** ([Obtenir gratuitement](https://makersuite.google.com/app/apikey))

### Étape 1 : Cloner le projet

```bash
# Cloner depuis GitHub
git clone git@github.com:Maureenhddi/rgaa-audit-app.git

# Se déplacer dans le répertoire
cd rgaa-audit-app
```

### Étape 2 : Configuration de l'environnement

```bash
# Copier le fichier d'exemple
cp .env.local.example .env.local
```

**Éditer le fichier `.env.local` :**

```bash
# Ouvrir avec votre éditeur préféré
nano .env.local
# ou
code .env.local
```

**Configurer les variables suivantes :**

```env
###> Symfony Framework ###
APP_ENV=dev
APP_SECRET=changez_ce_secret_32_caracteres_minimum_ici_par_une_chaine_aleatoire
###< Symfony Framework ###

###> Base de données (pas besoin de changer avec Docker) ###
DATABASE_URL="mysql://root:root@db:3306/rgaa_audit?serverVersion=8.0&charset=utf8mb4"
###< Base de données ###

###> Google Gemini API (OBLIGATOIRE) ###
GEMINI_API_KEY=VOTRE_CLE_API_GEMINI_ICI
GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent
###< Google Gemini API ###

###> Scripts Node.js (chemins Docker - ne pas modifier) ###
NODE_SCRIPTS_PATH=/var/www/html/audit-scripts
NODE_EXECUTABLE=node
###< Scripts Node.js ###
```

**⚠️ Important :**
- Remplacez `VOTRE_CLE_API_GEMINI_ICI` par votre vraie clé API
- Générez un `APP_SECRET` aléatoire (32+ caractères)

### Étape 3 : Construire et démarrer les conteneurs Docker

```bash
# Construire les images et démarrer tous les services
docker compose up -d --build
```

**Ce que fait cette commande :**
- 📦 Télécharge les images Docker (Nginx, PHP 8.2, MySQL 8.0)
- 🔨 Construit l'image PHP avec toutes les extensions
- 🚀 Démarre 3 conteneurs : `web` (Nginx), `php`, `db` (MySQL)
- 🔗 Configure le réseau entre les conteneurs

**Temps d'installation :** 5-10 minutes (première fois uniquement)

### Étape 4 : Installer les dépendances PHP

```bash
# Entrer dans le conteneur PHP
docker compose exec php bash

# Installer les dépendances Composer
composer install --no-interaction --optimize-autoloader

# Sortir du conteneur
exit
```

### Étape 5 : Installer les dépendances Node.js

```bash
# Installer npm packages dans le conteneur
docker compose exec php bash -c "cd /var/www/html/audit-scripts && npm install"

# Installer les navigateurs Playwright (Chromium)
docker compose exec php bash -c "cd /var/www/html/audit-scripts && npx playwright install chromium --with-deps"
```

**Ce que fait cette commande :**
- Installe Playwright et toutes ses dépendances
- Télécharge Chromium headless (~200 MB)
- Configure l'environnement pour les tests d'accessibilité

### Étape 6 : Initialiser la base de données

```bash
# Créer la base de données si elle n'existe pas
docker compose exec php php bin/console doctrine:database:create --if-not-exists

# Exécuter les migrations pour créer les tables
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

**Tables créées :**
- `user` - Utilisateurs de l'application
- `audit` - Audits d'accessibilité
- `audit_result` - Résultats détaillés des audits
- `manual_check` - Vérifications manuelles RGAA

### Étape 7 : Accéder à l'application

Ouvrez votre navigateur et accédez à :

```
http://localhost:8080
```

🎉 **L'application est prête !**

<details>
<summary>💡 Vérifier que tout fonctionne (optionnel)</summary>

```bash
# Vérifier que les 3 conteneurs tournent
docker compose ps

# Voir les logs si besoin
docker compose logs -f
```
</details>

### Premiers pas

1. **Créer un compte :**
   - Cliquez sur "S'inscrire"
   - Remplissez le formulaire (nom, email, mot de passe)
   - Connectez-vous

2. **Lancer votre premier audit :**
   - Cliquez sur "Nouvel audit"
   - Entrez une URL (ex: `https://www.example.com`)
   - Cliquez sur "Lancer l'audit"
   - Patientez 2-5 minutes

3. **Consulter les résultats :**
   - Vue d'ensemble avec statistiques
   - Grille RGAA complète (106 critères)
   - Détails des problèmes détectés

---

### Commandes Docker utiles

```bash
# Démarrer les conteneurs
docker compose up -d

# Arrêter les conteneurs
docker compose down

# Voir les logs en temps réel
docker compose logs -f

# Voir les logs d'un service spécifique
docker compose logs -f php

# Redémarrer un service
docker compose restart php

# Accéder au conteneur PHP (bash)
docker compose exec php bash

# Accéder à MySQL
docker compose exec db mysql -uroot -proot rgaa_audit

# Vider le cache Symfony
docker compose exec php php bin/console cache:clear

# Voir les routes disponibles
docker compose exec php php bin/console debug:router

# Reconstruire les conteneurs (après modification Dockerfile)
docker compose up -d --build --force-recreate
```

---

### Dépannage

**Problème : Port 8080 déjà utilisé**
```bash
# Modifier le port dans docker-compose.yml
ports:
  - "8081:80"  # Utiliser 8081 au lieu de 8080
```

**Problème : Erreur de connexion base de données**
```bash
# Vérifier que le conteneur MySQL est démarré
docker compose ps

# Vérifier les logs MySQL
docker compose logs db

# Recréer la base de données
docker compose exec php php bin/console doctrine:database:drop --force --if-exists
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

**Problème : Playwright ne fonctionne pas**
```bash
# Réinstaller Playwright avec dépendances système
docker compose exec php bash -c "cd /var/www/html/audit-scripts && npx playwright install chromium --with-deps"
```

**Problème : Cache Symfony corrompu**
```bash
# Vider complètement le cache
docker compose exec php rm -rf var/cache/*
docker compose exec php php bin/console cache:clear
```

---

### Architecture Docker

Le projet utilise 3 conteneurs :

```
┌─────────────────────────────────────┐
│   Navigateur → http://localhost:8080│
└──────────────┬──────────────────────┘
               │
         ┌─────▼─────┐
         │   Nginx   │  Port 8080 → 80
         │   (web)   │  Serveur web
         └─────┬─────┘
               │
         ┌─────▼─────┐
         │  PHP-FPM  │  PHP 8.2 + Extensions
         │   (php)   │  Symfony + Composer
         │           │  Node.js + Playwright
         └─────┬─────┘
               │
         ┌─────▼─────┐
         │   MySQL   │  Port 3306
         │    (db)   │  Base de données
         └───────────┘
```

**Volumes Docker :**
- `./` → `/var/www/html` (code source synchronisé)
- `db_data` → Données MySQL persistantes

**Réseau Docker :**
- Tous les conteneurs communiquent via le réseau `rgaa_network`

---

## ⚙️ Configuration

### Variables d'environnement

Fichier `.env.local` :

```env
# Environnement
APP_ENV=dev
APP_SECRET=votre_secret_32_caracteres_minimum

# Base de données
DATABASE_URL="mysql://user:password@db:3306/rgaa_audit?serverVersion=8.0&charset=utf8mb4"

# Google Gemini API
GEMINI_API_KEY=votre_cle_api_gemini
GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent

# Node.js (chemin dans le conteneur Docker)
NODE_SCRIPTS_PATH=/var/www/html/audit-scripts
NODE_EXECUTABLE=node
```

### Obtenir une clé API Google Gemini

1. Visitez [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Connectez-vous avec votre compte Google
3. Cliquez sur "Create API Key"
4. Copiez la clé et collez-la dans `.env.local`

**Note :** L'API Gemini est gratuite avec limites raisonnables (60 requêtes/minute).

---

## 🎯 Utilisation

### 1. Créer un compte

- Accédez à http://localhost:8080/register
- Remplissez le formulaire (nom, email, mot de passe)
- Connectez-vous avec vos identifiants

### 2. Lancer un audit

1. Cliquez sur **"Nouvel audit"** dans la navigation
2. Entrez l'URL du site à auditer (ex: `https://www.example.com`)
3. Cliquez sur **"Lancer l'audit"**
4. Patientez 2-5 minutes (l'audit s'exécute automatiquement)

### 3. Consulter les résultats

Les résultats sont organisés en **3 onglets** :

#### 📊 Vue d'ensemble
- Taux de conformité global
- Statistiques par criticité (Critique/Majeur/Mineur)
- Répartition RGAA (Conforme/Non-conforme/Non applicable)
- Résumé généré par IA

#### ✅ Grille RGAA (106 critères)
- Accordéons par thématique (Images, Couleurs, Navigation, etc.)
- Statut pour chaque critère avec radio buttons
- Description de chaque critère
- Sauvegarde automatique

#### 🔍 Détail des problèmes
- Groupement par thème RGAA
- Badges de source (Playwright / Axe-core / HTML_CodeSniffer)
- Pour chaque erreur :
  - Description du problème
  - Impact sur les utilisateurs
  - Recommandation de correction
  - Exemple de code fix
  - Critères WCAG et RGAA concernés

### 4. Vérifications manuelles

Certains critères ne peuvent pas être vérifiés automatiquement. Pour ces critères :

1. Allez dans l'onglet **"Grille RGAA"**
2. Vérifiez manuellement chaque critère
3. Sélectionnez : **Conforme** / **Non-conforme** / **Non applicable**
4. Les changements sont sauvegardés automatiquement
5. Le taux de conformité est recalculé en temps réel

### 5. Exporter en PDF

- Cliquez sur **"Exporter en PDF"** depuis la page de résultats
- Un rapport professionnel est généré avec :
  - Page de garde
  - Statistiques et graphiques
  - Liste complète des problèmes
  - Recommandations et exemples de code

### 6. Comparer des audits

1. Accédez à **"Mes audits"**
2. Sélectionnez deux audits (même URL, dates différentes)
3. Cliquez sur **"Comparer"**
4. Visualisez l'évolution :
   - Différence de conformité
   - Évolution par criticité
   - Problèmes résolus vs nouveaux

---

## 🏗 Architecture

### Structure du projet

```
rgaa-audit-app/
├── audit-scripts/              # Scripts Node.js pour audit
│   ├── playwright-audit.js     # Tests Playwright + Axe + HTML_CodeSniffer
│   ├── package.json
│   └── README.md
├── config/
│   ├── packages/               # Configuration Symfony
│   ├── routes.yaml             # Routes de l'application
│   ├── services.yaml           # Services et DI
│   └── rgaa_criteria.json      # 106 critères RGAA (source unique)
├── migrations/                 # Migrations Doctrine
├── public/
│   ├── images/                 # Assets images
│   └── index.php               # Point d'entrée
├── src/
│   ├── Controller/             # Contrôleurs MVC
│   │   ├── AuditController.php
│   │   ├── DashboardController.php
│   │   ├── ExportController.php
│   │   ├── ManualCheckController.php
│   │   └── SecurityController.php
│   ├── Entity/                 # Entités Doctrine
│   │   ├── Audit.php
│   │   ├── AuditResult.php
│   │   ├── ManualCheck.php
│   │   └── User.php
│   ├── Enum/                   # Constantes type-safe
│   │   ├── AuditStatus.php     # PENDING, RUNNING, COMPLETED, FAILED
│   │   ├── IssueSeverity.php   # CRITICAL, MAJOR, MINOR
│   │   └── IssueSource.php     # PLAYWRIGHT, AXE_CORE, HTML_CODESNIFFER
│   ├── Form/
│   │   └── RegistrationFormType.php
│   ├── Repository/
│   │   ├── AuditRepository.php
│   │   ├── AuditResultRepository.php
│   │   ├── ManualCheckRepository.php
│   │   └── UserRepository.php
│   ├── Security/
│   │   └── AuditVoter.php      # Contrôle d'accès aux audits
│   ├── Service/                # Logique métier
│   │   ├── AuditService.php          # Orchestration audit complet
│   │   ├── PlaywrightService.php     # Exécution Playwright
│   │   ├── GeminiService.php         # Analyse IA
│   │   ├── RgaaThemeService.php      # Gestion thèmes RGAA (JSON-based)
│   │   ├── RgaaReferenceService.php  # Référence des critères
│   │   └── PdfExportService.php      # Export PDF
│   └── Kernel.php
├── templates/                  # Templates Twig
│   ├── audit/
│   │   ├── show.html.twig      # Page de résultats (tabs)
│   │   ├── list.html.twig
│   │   ├── new.html.twig
│   │   ├── compare.html.twig
│   │   └── pdf_report.html.twig
│   ├── dashboard/
│   ├── security/
│   └── base.html.twig          # Layout de base (CSS, navbar, footer)
├── var/                        # Cache, logs (non versionné)
├── vendor/                     # Dépendances Composer (non versionné)
├── .env.local.example          # Template configuration
├── .gitignore
├── composer.json
├── docker-compose.yml
└── README.md
```

### Pattern architectural

**MVC avec Services découplés :**

```
User Request
     ↓
Controller (AuditController)
     ↓
Service Layer (AuditService → PlaywrightService → GeminiService)
     ↓
Repository (AuditRepository)
     ↓
Entity (Audit, AuditResult)
     ↓
Database (MySQL)
     ↓
View (Twig templates)
     ↓
User Response
```

### Services clés

#### `AuditService`
Orchestre le processus complet d'audit :
- Lance Playwright + Axe + HTML_CodeSniffer
- Stocke les résultats bruts
- Envoie à Gemini pour enrichissement
- Met à jour avec recommandations IA
- Calcule les statistiques

#### `PlaywrightService`
Exécute le script Node.js Playwright :
- Lance navigateur Chromium headless
- Exécute Axe-core sur la page
- Exécute HTML_CodeSniffer
- Retourne résultats JSON unifiés

#### `GeminiService`
Communique avec Google Gemini API :
- Envoie résultats bruts + contexte RGAA
- Reçoit recommandations enrichies
- Parse et structure les données
- Mappe WCAG → RGAA

#### `RgaaThemeService`
Gestion des thèmes et critères RGAA :
- Charge dynamiquement depuis `rgaa_criteria.json`
- 13 thèmes avec métadonnées (icône, couleur)
- Mapping critère → thème
- Descriptions des 106 critères

#### `PdfExportService`
Génération de rapports PDF :
- Rendu Twig → HTML
- Conversion HTML → PDF via wkhtmltopdf
- Graphiques Chart.js inclus
- Mise en page professionnelle

---

## 🔄 Processus d'audit

### Étapes automatiques

```
1. Utilisateur soumet URL
         ↓
2. AuditService crée entité Audit (status: RUNNING)
         ↓
3. PlaywrightService lance script Node.js
         ↓
4. Playwright ouvre URL dans Chromium headless
         ↓
5. Exécution Axe-core → Détecte violations WCAG
         ↓
6. Exécution HTML_CodeSniffer → Détecte problèmes HTML/CSS
         ↓
7. Retour JSON avec tous les résultats
         ↓
8. AuditService stocke résultats bruts (AuditResult entities)
         ↓
9. GeminiService analyse les résultats
         ↓
10. Gemini 2.5 Flash génère :
    - Recommandations personnalisées
    - Exemples de code
    - Impact utilisateur
    - Mapping WCAG → RGAA
    - Résumé général
         ↓
11. AuditService enrichit les résultats stockés
         ↓
12. Calcul statistiques (conformité, critères, criticité)
         ↓
13. Audit status → COMPLETED
         ↓
14. Redirection vers page de résultats
```

**Durée totale :** 2-5 minutes selon taille du site

---

## 📚 Documentation

- **[INSTALLATION.md](INSTALLATION.md)** - Guide d'installation détaillé
- **[DOCKER.md](DOCKER.md)** - Configuration Docker complète
- **[ARCHITECTURE.md](ARCHITECTURE.md)** - Architecture technique approfondie
- **[CHANGELOG.md](CHANGELOG.md)** - Historique des versions

---

## 🗄️ Base de données

### Schéma principal

**Table `user`**
```sql
id INT PRIMARY KEY
email VARCHAR(180) UNIQUE
password VARCHAR(255)
name VARCHAR(255)
roles JSON
created_at DATETIME
```

**Table `audit`**
```sql
id INT PRIMARY KEY
user_id INT FOREIGN KEY
url VARCHAR(500)
status VARCHAR(50)  -- pending|running|completed|failed
conformity_rate DECIMAL(5,2)
summary TEXT
critical_count INT
major_count INT
minor_count INT
total_issues INT
conform_criteria INT
non_conform_criteria INT
not_applicable_criteria INT
non_conform_details JSON
error_message TEXT
created_at DATETIME
updated_at DATETIME
```

**Table `audit_result`**
```sql
id INT PRIMARY KEY
audit_id INT FOREIGN KEY
error_type VARCHAR(255)
severity VARCHAR(50)  -- critical|major|minor
description TEXT
recommendation TEXT
code_fix TEXT
selector VARCHAR(500)
context TEXT
wcag_criteria VARCHAR(100)
rgaa_criteria VARCHAR(100)
impact_user TEXT
source VARCHAR(50)  -- playwright|axe-core|html_codesniffer
created_at DATETIME
```

**Table `manual_check`**
```sql
id INT PRIMARY KEY
audit_id INT FOREIGN KEY
criterion_number VARCHAR(10)
status VARCHAR(50)  -- conform|non_conform|not_applicable
created_at DATETIME
updated_at DATETIME
```

---

## 🧪 Développement

### Commandes utiles

```bash
# Démarrer les conteneurs
docker compose up -d

# Voir les logs
docker compose logs -f php

# Accéder au conteneur PHP
docker compose exec php bash

# Exécuter migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Vider le cache Symfony
docker compose exec php php bin/console cache:clear

# Lister les routes
docker compose exec php php bin/console debug:router

# Arrêter les conteneurs
docker compose down
```

### Structure des Enums

**Nouvelles constantes type-safe (PHP 8.1+) :**

```php
// src/Enum/AuditStatus.php
AuditStatus::PENDING
AuditStatus::RUNNING
AuditStatus::COMPLETED
AuditStatus::FAILED

// src/Enum/IssueSeverity.php
IssueSeverity::CRITICAL
IssueSeverity::MAJOR
IssueSeverity::MINOR

// src/Enum/IssueSource.php
IssueSource::PLAYWRIGHT
IssueSource::AXE_CORE
IssueSource::HTML_CODESNIFFER
IssueSource::UNKNOWN
```

---

## 🎨 Personnalisation

### Couleurs du thème

Fichier `templates/base.html.twig` :

```css
:root {
    --primary-color: #f59c16;      /* Orange - boutons principaux */
    --primary-dark: #d98913;
    --info-color: #016dae;         /* Bleu - liens */
    --info-dark: #014d7a;
    --secondary-color: #5a6268;    /* Gris moderne */
    --text-medium: #4c4c4c;        /* Texte principal */
}
```

### Thèmes RGAA

Fichier `config/rgaa_criteria.json` :
- Source unique pour les 106 critères
- Modifiable sans toucher au code PHP
- Structure JSON claire

```json
{
  "criteria": [
    {
      "number": "1.1",
      "topic": "Images",
      "title": "Chaque image porteuse d'information a-t-elle une alternative textuelle ?"
    },
    ...
  ]
}
```

---

## 🤝 Contribution

Les contributions sont les bienvenues !

### Process de contribution

1. Forkez le projet
2. Créez une branche feature (`git checkout -b feature/AmazingFeature`)
3. Committez vos changements (`git commit -m 'Add some AmazingFeature'`)
4. Pushez vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrez une Pull Request

### Coding standards

- Suivre **PSR-12** pour PHP
- Suivre **Symfony Best Practices**
- Documenter les fonctions publiques
- Tests unitaires pour nouvelle logique métier

---

## 🙏 Crédits

### Technologies

- [Symfony](https://symfony.com/) - Framework PHP
- [Playwright](https://playwright.dev/) - Automation browser testing
- [Axe-core](https://github.com/dequelabs/axe-core) - Accessibility testing engine
- [HTML_CodeSniffer](https://squizlabs.github.io/HTML_CodeSniffer/) - WCAG validator
- [Google Gemini](https://ai.google.dev/) - Intelligence artificielle
- [Bootstrap](https://getbootstrap.com/) - Framework CSS
- [Chart.js](https://www.chartjs.org/) - Graphiques JavaScript
- [Docker](https://www.docker.com/) - Containerization

### Référentiels

- [RGAA 4.1](https://accessibilite.numérique.gouv.fr/) - Référentiel français
- [WCAG 2.1](https://www.w3.org/WAI/WCAG21/quickref/) - Standards W3C

---

## 📄 Licence

MIT License - © 2024-2025 IT Room

---

## 📞 Support

Pour toute question ou problème :

- 📧 Email : mhaddadi@itroom.fr
- 🐛 Issues : [GitHub Issues](https://github.com/Maureenhddi/rgaa-audit-app/issues)
- 📖 Documentation : Voir fichiers `*.md` du projet

---

**Développé avec ❤️ pour rendre le web plus accessible à tous**
