# Architecture - RGAA Audit Application

Ce document décrit l'architecture complète de l'application RGAA Audit.

## 📊 Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────┐
│                       UTILISATEUR                            │
│                   (Navigateur Web)                           │
└────────────────────────┬────────────────────────────────────┘
                         │ HTTP/HTTPS
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                    NGINX (Port 8080)                         │
│              Serveur web / Reverse proxy                     │
└────────────────────────┬────────────────────────────────────┘
                         │ FastCGI
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                   PHP-FPM + SYMFONY                          │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ Controllers (MVC)                                     │  │
│  │  • SecurityController (Auth)                          │  │
│  │  • DashboardController (Stats)                        │  │
│  │  • AuditController (CRUD audits)                      │  │
│  │  • ExportController (PDF)                             │  │
│  └────────────────────┬──────────────────────────────────┘  │
│                       │                                      │
│  ┌────────────────────▼──────────────────────────────────┐  │
│  │ Services (Business Logic)                             │  │
│  │  • AuditService (Orchestration)                       │  │
│  │  • PlaywrightService ──────┐                          │  │
│  │  • Pa11yService ────────┐  │                          │  │
│  │  • GeminiService        │  │                          │  │
│  │  • PdfExportService     │  │                          │  │
│  └─────────────────────────┼──┼──────────────────────────┘  │
│                            │  │                              │
│  ┌─────────────────────────▼──▼──────────────────────────┐  │
│  │ Node.js Scripts                                       │  │
│  │  • playwright-audit.js (Tests interactivité)         │  │
│  │  • pa11y-audit.js (Analyse HTML/CSS)                 │  │
│  │  • Playwright + Chromium                             │  │
│  └───────────────────────────────────────────────────────┘  │
│                            │                                 │
│  ┌─────────────────────────▼──────────────────────────────┐ │
│  │ Doctrine ORM                                          │  │
│  │  • UserRepository                                     │  │
│  │  • AuditRepository                                    │  │
│  │  • AuditResultRepository                              │  │
│  └────────────────────────┬──────────────────────────────┘  │
└─────────────────────────────┼──────────────────────────────┘
                              │ SQL
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    MySQL 8.0 (Port 3306)                     │
│                   Base de données                            │
│  Tables: user, audit, audit_result                          │
└─────────────────────────────────────────────────────────────┘

External API:
                              │ HTTPS
                              ▼
┌─────────────────────────────────────────────────────────────┐
│              Google Gemini API                               │
│    Analyse contextuelle + Recommandations                    │
└─────────────────────────────────────────────────────────────┘
```

## 🏗 Composants principaux

### 1. Frontend (Twig Templates)

**Localisation :** `templates/`

Utilise Bootstrap 5 et Chart.js pour l'interface utilisateur.

**Pages principales :**
- `base.html.twig` : Template de base avec navbar et sidebar
- `security/login.html.twig` : Page de connexion
- `security/register.html.twig` : Page d'inscription
- `dashboard/index.html.twig` : Dashboard avec statistiques et graphiques
- `audit/new.html.twig` : Formulaire de création d'audit
- `audit/show.html.twig` : Affichage détaillé des résultats
- `audit/list.html.twig` : Historique des audits
- `audit/compare.html.twig` : Comparaison de deux audits
- `audit/pdf_report.html.twig` : Template pour export PDF

### 2. Backend Symfony

#### Contrôleurs (`src/Controller/`)

| Contrôleur | Routes | Responsabilité |
|------------|--------|----------------|
| `SecurityController` | `/login`, `/register`, `/logout` | Authentification |
| `DashboardController` | `/` | Dashboard principal |
| `AuditController` | `/audit/*` | CRUD des audits |
| `ExportController` | `/export/audit/{id}/pdf` | Export PDF |

#### Services métier (`src/Service/`)

**AuditService** (Orchestrateur principal)
```php
runCompleteAudit(url, user) -> Audit
├── PlaywrightService::runAudit()
├── Pa11yService::runAudit()
├── GeminiService::analyzeResults()
└── storeAuditResults()
```

**PlaywrightService**
- Exécute le script Node.js `playwright-audit.js`
- Tests d'interactivité : clavier, focus, formulaires
- Retourne JSON avec les résultats

**Pa11yService**
- Exécute le script Node.js `pa11y-audit.js`
- Analyse HTML/CSS WCAG 2.1 AA
- Utilise axe-core et htmlcs
- Retourne JSON avec les problèmes détectés

**GeminiService**
- Envoie les résultats à Google Gemini API
- Prompt structuré pour analyse RGAA
- Parse la réponse JSON avec recommandations
- Génère le résumé et les statistiques

**PdfExportService**
- Génère un PDF à partir du template Twig
- Utilise wkhtmltopdf (via Knp Snappy Bundle)
- Mise en page professionnelle

#### Entités (`src/Entity/`)

**User**
```
id, email, password, name, roles, created_at
└── OneToMany: audits
```

**Audit**
```
id, user_id, url, status, conformity_rate
created_at, updated_at, summary
critical_count, major_count, minor_count, total_issues
conform_criteria, non_conform_criteria, not_applicable_criteria
error_message
└── OneToMany: audit_results
```

**AuditResult**
```
id, audit_id, error_type, severity, description
recommendation, code_fix, selector, context
wcag_criteria, rgaa_criteria, impact_user
source (playwright|pa11y), created_at
```

#### Repositories (`src/Repository/`)

**AuditRepository**
- `findByUserOrderedByDate()` : Audits d'un utilisateur
- `getConformityEvolution()` : Évolution du taux de conformité
- `getUserStatistics()` : Statistiques agrégées

**AuditResultRepository**
- `findGroupedBySeverity()` : Résultats groupés par criticité
- `countBySeverity()` : Compteurs par criticité

### 3. Scripts Node.js d'audit

**Localisation :** `audit-scripts/`

#### playwright-audit.js

Tests effectués :
1. **Keyboard Navigation** : Éléments focusables, tabindex
2. **Focus Management** : Indicateurs visuels de focus
3. **Interactive Elements** : Boutons sémantiques, noms accessibles
4. **Dynamic Content** : Régions live, aria-live
5. **Form Accessibility** : Labels, champs requis
6. **Skip Links** : Liens d'évitement

Format de sortie :
```json
{
  "url": "...",
  "timestamp": "...",
  "tests": [
    {
      "name": "...",
      "category": "...",
      "status": "passed|failed|warning|error",
      "issues": [
        {
          "severity": "critical|major|minor",
          "message": "...",
          "selector": "...",
          "context": "..."
        }
      ]
    }
  ],
  "summary": {
    "passed": 0,
    "failed": 0,
    "warnings": 0
  }
}
```

#### pa11y-audit.js

Utilise Pa11y avec les runners :
- **axe-core** : Tests d'accessibilité automatisés
- **htmlcs** : Validation WCAG 2.1

Format de sortie :
```json
{
  "url": "...",
  "timestamp": "...",
  "issues": [
    {
      "code": "...",
      "type": "error|warning|notice",
      "message": "...",
      "context": "...",
      "selector": "...",
      "runner": "axe|htmlcs",
      "severity": "critical|major|minor"
    }
  ],
  "summary": {
    "errors": 0,
    "warnings": 0,
    "notices": 0
  }
}
```

### 4. Base de données MySQL

**Tables principales :**

```sql
user (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(180) UNIQUE,
  roles JSON,
  password VARCHAR(255),
  name VARCHAR(255),
  created_at DATETIME
)

audit (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT FOREIGN KEY,
  url VARCHAR(500),
  status VARCHAR(50),
  conformity_rate DECIMAL(5,2),
  summary TEXT,
  critical_count INT,
  major_count INT,
  minor_count INT,
  total_issues INT,
  conform_criteria INT,
  non_conform_criteria INT,
  not_applicable_criteria INT,
  error_message TEXT,
  created_at DATETIME,
  updated_at DATETIME
)

audit_result (
  id INT PRIMARY KEY AUTO_INCREMENT,
  audit_id INT FOREIGN KEY,
  error_type VARCHAR(100),
  severity VARCHAR(50),
  description TEXT,
  recommendation TEXT,
  code_fix TEXT,
  selector TEXT,
  context TEXT,
  wcag_criteria VARCHAR(255),
  rgaa_criteria VARCHAR(255),
  impact_user TEXT,
  source VARCHAR(50),
  created_at DATETIME
)
```

## 🔄 Flux de données

### Flux d'audit complet

```
1. Utilisateur entre URL
   └──> AuditController::new()

2. Création entité Audit (status: pending)
   └──> AuditService::runCompleteAudit()

3. Audit Playwright
   ├──> PlaywrightService::runAudit()
   ├──> Exécution playwright-audit.js
   └──> Retour JSON résultats

4. Audit Pa11y (parallèle)
   ├──> Pa11yService::runAudit()
   ├──> Exécution pa11y-audit.js
   └──> Retour JSON résultats

5. Analyse Gemini AI
   ├──> GeminiService::analyzeResults()
   ├──> Envoi résultats à Gemini API
   ├──> Parse réponse JSON
   └──> Extraction recommandations

6. Stockage en BDD
   ├──> AuditService::storeAuditResults()
   ├──> Création AuditResult pour chaque problème
   ├──> Calcul statistiques (conformityRate, counts)
   └──> Mise à jour Audit (status: completed)

7. Affichage résultats
   └──> AuditController::show()
       └──> Template audit/show.html.twig
```

### Flux d'authentification

```
1. Inscription
   └──> SecurityController::register()
       ├──> Validation formulaire
       ├──> Hash du mot de passe
       └──> Création User en BDD

2. Connexion
   └──> SecurityController::login()
       ├──> Vérification credentials
       ├──> Création session
       └──> Redirection dashboard

3. Protection des routes
   └──> AuditVoter::voteOnAttribute()
       └──> Vérification propriété audit
```

## 🐳 Infrastructure Docker

### Services Docker

**nginx** (Port 8080)
- Image : `nginx:alpine`
- Rôle : Serveur web, reverse proxy vers PHP-FPM
- Config : `docker/nginx/`

**php** (Port 9000 interne)
- Image : Custom (PHP 8.2 + Node.js + Playwright)
- Dockerfile : `docker/php/Dockerfile`
- Volumes : Code source monté
- Dépendances : Composer, npm, Playwright browsers

**db** (Port 3306)
- Image : `mysql:8.0`
- Volume persistant : `db_data`
- Healthcheck : mysqladmin ping

### Réseau Docker

Tous les services communiquent via le réseau bridge `rgaa_network`.

```
nginx:80 ──FastCGI──> php:9000
                       │
                       └──SQL──> db:3306
```

## 🔐 Sécurité

### Authentification
- Symfony Security Bundle
- Hash bcrypt des mots de passe
- Protection CSRF sur tous les formulaires
- Sessions sécurisées

### Autorisation
- Voters Symfony pour contrôle d'accès
- Chaque utilisateur accède uniquement à ses audits
- Routes protégées par firewall

### Validation
- Validation Symfony sur formulaires
- Sanitization des URLs
- Protection XSS (auto Twig)
- Protection SQL injection (Doctrine ORM)

### Docker
- Utilisateur non-root dans les conteneurs
- Secrets via variables d'environnement
- Réseau isolé
- Port MySQL non exposé en production

## 📈 Performance

### Optimisations

**PHP**
- OPcache activé
- Realpath cache
- Autoloader optimisé en production

**Doctrine**
- Eager loading des relations
- Query optimization
- Result cache

**Nginx**
- Gzip compression
- Cache des fichiers statiques
- Keepalive connections

**Node.js**
- Chromium headless
- Timeout de 5 minutes max
- Exécution en arrière-plan

### Monitoring

Logs disponibles :
- Symfony : `var/log/dev.log`, `var/log/prod.log`
- Nginx : `/var/log/nginx/`
- PHP : `/var/www/html/var/log/php_errors.log`
- Docker : `docker compose logs`

## 🧪 Tests

### Tests unitaires (à implémenter)
- Services
- Repositories
- Voters

### Tests fonctionnels (à implémenter)
- Controllers
- Formulaires
- Workflows

### Tests E2E (à implémenter)
- Cypress ou Playwright
- Parcours utilisateur complet

## 📦 Dépendances

### PHP (Composer)
- symfony/framework-bundle
- doctrine/orm
- symfony/security-bundle
- symfony/twig-bundle
- symfony/http-client
- knplabs/knp-snappy-bundle

### Node.js (npm)
- playwright
- @playwright/test
- pa11y
- axe-core

### Externes
- Google Gemini API
- wkhtmltopdf

## 🚀 Déploiement

### Environnements

**Développement**
```env
APP_ENV=dev
APP_DEBUG=1
```

**Production**
```env
APP_ENV=prod
APP_DEBUG=0
```

### CI/CD (à implémenter)

Pipeline suggéré :
1. Tests unitaires
2. Tests fonctionnels
3. Build Docker image
4. Push vers registry
5. Deploy sur serveur
6. Health check

## 📚 Documentation

- [README.md](README.md) : Documentation complète
- [QUICKSTART.md](QUICKSTART.md) : Démarrage rapide
- [DOCKER.md](DOCKER.md) : Guide Docker détaillé
- [INSTALLATION.md](INSTALLATION.md) : Installation manuelle
- [ARCHITECTURE.md](ARCHITECTURE.md) : Ce fichier

---

**Version :** 1.0.0
**Dernière mise à jour :** 2025
