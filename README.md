# RGAA Audit - Application d'audit d'accessibilité automatisé

Application Symfony pour automatiser les audits d'accessibilité RGAA (Référentiel Général d'Amélioration de l'Accessibilité) avec Playwright, Pa11y et Google Gemini AI.

## 🚀 Fonctionnalités

### MVP (Minimum Viable Product)

1. **Système d'authentification**
   - Inscription / Connexion utilisateur
   - Gestion de session sécurisée

2. **Lancement d'audit automatique**
   - Formulaire simple pour entrer l'URL
   - Audit automatique avec Playwright + Pa11y
   - Analyse contextuelle avec Gemini AI

3. **Résultats détaillés**
   - Affichage par criticité (Critique/Majeur/Mineur) avec accordions
   - Pour chaque erreur :
     - Description détaillée
     - Impact sur les utilisateurs
     - Recommandations de correction
     - Exemple de code pour fixer
     - Critères WCAG et RGAA concernés

4. **Statistiques RGAA**
   - 106 critères RGAA analysés
   - Taux de conformité global
   - Répartition : Conformes / Non conformes / Non applicables
   - Graphiques de visualisation

5. **Export PDF**
   - Rapport détaillé complet
   - Statistiques et recommandations
   - Formatage professionnel

6. **Historique et comparaison**
   - Liste de tous les audits effectués
   - Comparaison avant/après entre deux audits
   - Dashboard avec évolution de la conformité dans le temps

## 🛠 Stack technique

- **Backend** : Symfony 6.4+ (PHP 8.1+)
- **Base de données** : MySQL 8.0+ ou PostgreSQL 15+
- **Audit Node.js** :
  - Playwright (tests d'interactivité)
  - Pa11y (analyse HTML/CSS)
- **IA** : Google Gemini API
- **Frontend** : Twig + Bootstrap 5 + Chart.js
- **PDF** : Knp Snappy Bundle (wkhtmltopdf)

## 📁 Structure du projet

```
rgaa-audit-app/
├── audit-scripts/          # Scripts Node.js pour Playwright et Pa11y
│   ├── package.json
│   ├── playwright-audit.js
│   ├── pa11y-audit.js
│   └── README.md
├── config/                 # Configuration Symfony
│   ├── packages/
│   ├── routes.yaml
│   └── services.yaml
├── migrations/             # Migrations de base de données
├── public/                 # Point d'entrée web
│   └── index.php
├── src/
│   ├── Controller/         # Contrôleurs
│   │   ├── AuditController.php
│   │   ├── DashboardController.php
│   │   ├── ExportController.php
│   │   └── SecurityController.php
│   ├── Entity/             # Entités Doctrine
│   │   ├── Audit.php
│   │   ├── AuditResult.php
│   │   └── User.php
│   ├── Form/               # Formulaires
│   │   └── RegistrationFormType.php
│   ├── Repository/         # Repositories
│   │   ├── AuditRepository.php
│   │   ├── AuditResultRepository.php
│   │   └── UserRepository.php
│   ├── Security/           # Voters et sécurité
│   │   └── AuditVoter.php
│   ├── Service/            # Services métier
│   │   ├── AuditService.php
│   │   ├── GeminiService.php
│   │   ├── Pa11yService.php
│   │   ├── PdfExportService.php
│   │   └── PlaywrightService.php
│   └── Kernel.php
├── templates/              # Templates Twig
│   ├── audit/
│   ├── dashboard/
│   ├── security/
│   └── base.html.twig
├── .env                    # Variables d'environnement (template)
├── .env.local.example      # Exemple de configuration locale
├── composer.json           # Dépendances PHP
└── README.md
```

## 🐳 Installation avec Docker (Recommandé)

### Prérequis

- [Docker](https://docs.docker.com/get-docker/) 20.10+
- [Docker Compose](https://docs.docker.com/compose/install/) 2.0+
- 4 GB de RAM minimum

### Installation rapide

```bash
# 1. Copier la configuration
cp .env.docker .env.docker.local

# 2. Éditer .env.docker.local et configurer :
#    - GEMINI_API_KEY (obligatoire)
#    - Mots de passe MySQL
#    - APP_SECRET

# 3. Démarrer l'application
make start
# ou
docker compose build && docker compose up -d

# 4. Accéder à l'application
# http://localhost:8080
```

**Commandes utiles avec Docker :**

```bash
make help              # Voir toutes les commandes disponibles
make up                # Démarrer les services
make down              # Arrêter les services
make logs              # Voir les logs
make shell             # Accéder au conteneur PHP
make db-migrate        # Exécuter les migrations
```

📖 **Guide complet Docker** : Voir [DOCKER.md](DOCKER.md)

---

## 🔧 Installation manuelle (sans Docker)

### Prérequis

- PHP 8.1 ou supérieur
- Composer
- Node.js 18+ et npm
- MySQL 8.0+ ou PostgreSQL 15+
- wkhtmltopdf (pour l'export PDF)

### 1. Cloner et configurer le projet

```bash
cd rgaa-audit-app

# Copier le fichier d'environnement
cp .env.local.example .env.local

# Éditer .env.local et configurer :
# - DATABASE_URL
# - APP_SECRET
# - GEMINI_API_KEY
```

### 2. Installer les dépendances PHP

```bash
composer install
```

### 3. Installer les dépendances Node.js

```bash
cd audit-scripts
npm install
npm run install-browsers  # Installer Chromium pour Playwright
cd ..
```

### 4. Créer la base de données

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### 5. Lancer le serveur de développement

```bash
symfony server:start
# ou
php -S localhost:8000 -t public/
```

L'application sera accessible sur `http://localhost:8000`

## 🔑 Configuration

### Variables d'environnement (.env.local)

```env
# Base de données
DATABASE_URL="mysql://user:password@127.0.0.1:3306/rgaa_audit"

# Secret Symfony
APP_SECRET=votre_secret_de_32_caracteres_minimum

# Google Gemini API
GEMINI_API_KEY=votre_cle_api_gemini
GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent

# Scripts Node.js
NODE_SCRIPTS_PATH=/chemin/absolu/vers/rgaa-audit-app/audit-scripts
NODE_EXECUTABLE=node
```

### Obtenir une clé API Gemini

1. Allez sur [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Créez une nouvelle clé API
3. Copiez la clé dans votre `.env.local`

## 📊 Structure de la base de données

### Table `user`
- `id` : Identifiant unique
- `email` : Email de connexion (unique)
- `password` : Mot de passe hashé
- `name` : Nom complet
- `roles` : Rôles de l'utilisateur
- `created_at` : Date de création

### Table `audit`
- `id` : Identifiant unique
- `user_id` : Référence à l'utilisateur
- `url` : URL auditée
- `status` : pending|running|completed|failed
- `conformity_rate` : Taux de conformité (%)
- `summary` : Résumé de l'audit
- `critical_count` : Nombre de problèmes critiques
- `major_count` : Nombre de problèmes majeurs
- `minor_count` : Nombre de problèmes mineurs
- `total_issues` : Total des problèmes
- `conform_criteria` : Critères conformes
- `non_conform_criteria` : Critères non conformes
- `not_applicable_criteria` : Critères non applicables
- `error_message` : Message d'erreur (si échec)
- `created_at` : Date de création
- `updated_at` : Date de mise à jour

### Table `audit_result`
- `id` : Identifiant unique
- `audit_id` : Référence à l'audit
- `error_type` : Type d'erreur
- `severity` : critical|major|minor
- `description` : Description du problème
- `recommendation` : Recommandation de correction
- `code_fix` : Exemple de code corrigé
- `selector` : Sélecteur CSS de l'élément
- `context` : Contexte de l'erreur
- `wcag_criteria` : Critères WCAG (ex: 1.1.1)
- `rgaa_criteria` : Critères RGAA (ex: 1.1)
- `impact_user` : Impact sur l'utilisateur
- `source` : playwright|pa11y|gemini
- `created_at` : Date de création

## 🎯 Utilisation

### 1. Créer un compte

- Accédez à `/register`
- Remplissez le formulaire d'inscription
- Connectez-vous avec vos identifiants

### 2. Lancer un audit

- Cliquez sur "Nouvel audit"
- Entrez l'URL du site à auditer (ex: https://www.example.com)
- Cliquez sur "Lancer l'audit"
- L'audit s'exécute automatiquement (peut prendre 2-5 minutes)

### 3. Consulter les résultats

Les résultats sont organisés par criticité :

- **🔴 Critiques** : Bloquent l'accès au contenu
- **🟠 Majeurs** : Impact significatif sur l'expérience
- **🟡 Mineurs** : Améliorations recommandées

Chaque problème contient :
- Description détaillée
- Impact sur les utilisateurs
- Recommandations de correction
- Exemple de code pour fixer
- Critères WCAG et RGAA concernés

### 4. Exporter en PDF

- Cliquez sur "Exporter PDF" depuis la page de résultats
- Un rapport complet est généré avec toutes les informations

### 5. Comparer des audits

- Accédez à l'historique
- Sélectionnez deux audits à comparer
- Visualisez l'évolution entre les deux audits

## 🏗 Architecture modulaire

### Services

Les services sont découplés et réutilisables :

- **`AuditService`** : Orchestre l'audit complet
- **`PlaywrightService`** : Exécute les tests d'interactivité
- **`Pa11yService`** : Analyse HTML/CSS
- **`GeminiService`** : Génère les analyses contextuelles
- **`PdfExportService`** : Exporte les rapports en PDF

### Extensibilité

Pour ajouter de nouveaux outils d'audit :

1. Créer un nouveau service dans `src/Service/`
2. Implémenter la méthode `runAudit(string $url): array`
3. Intégrer dans `AuditService::runCompleteAudit()`

## 🧪 Scripts Node.js

### playwright-audit.js

Tests d'interactivité et de navigation :
- Navigation au clavier
- Gestion du focus
- Éléments interactifs
- Contenu dynamique
- Accessibilité des formulaires
- Liens d'évitement

### pa11y-audit.js

Analyse HTML/CSS :
- Conformité WCAG 2.1 AA
- Structure sémantique
- Attributs ARIA
- Contraste des couleurs
- Alternatives textuelles

## 📝 TODO / Améliorations futures

- [ ] Audit de plusieurs pages en parallèle
- [ ] Tests automatiques récurrents (cron)
- [ ] Notifications par email
- [ ] API REST pour intégration CI/CD
- [ ] Support multi-langue
- [ ] Tests unitaires et fonctionnels
- [ ] Interface d'administration
- [ ] Gestion d'équipes et de projets
- [ ] Rapports personnalisables
- [ ] Intégration Slack/Discord

## 📄 Licence

Propriétaire - Tous droits réservés

## 🤝 Support

Pour toute question ou problème :
- Créer une issue sur le dépôt Git
- Contacter l'équipe de développement

## 🙏 Crédits

- [Symfony](https://symfony.com/)
- [Playwright](https://playwright.dev/)
- [Pa11y](https://pa11y.org/)
- [Google Gemini](https://ai.google.dev/)
- [Bootstrap](https://getbootstrap.com/)
- [Chart.js](https://www.chartjs.org/)
