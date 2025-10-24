# 🚀 START HERE - RGAA Audit Application

**Bienvenue dans l'application RGAA Audit !**

Ce fichier est votre point d'entrée pour démarrer rapidement.

---

## ⚡ Démarrage ultra-rapide (5 minutes)

### 1️⃣ Prérequis
- Docker installé ([télécharger ici](https://docs.docker.com/get-docker/))
- 4 GB de RAM disponibles

### 2️⃣ Configuration
```bash
cp .env.docker .env.docker.local
nano .env.docker.local  # ou votre éditeur préféré
```

**Modifiez ces 3 valeurs OBLIGATOIRES :**
```env
GEMINI_API_KEY=votre_cle_api_gemini     # Obtenez-la sur https://makersuite.google.com/app/apikey
MYSQL_ROOT_PASSWORD=changez_moi
MYSQL_PASSWORD=changez_moi_aussi
```

### 3️⃣ Lancement
```bash
make start
```
⏱️ Attendez 2-3 minutes...

### 4️⃣ Accès
Ouvrez votre navigateur : **http://localhost:8080**

🎉 **C'est tout ! Vous êtes prêt !**

---

## 📚 Quelle documentation lire ?

### Je débute avec le projet
👉 Lisez **[QUICKSTART.md](QUICKSTART.md)** (5 min de lecture)

### Je veux comprendre l'architecture
👉 Lisez **[ARCHITECTURE.md](ARCHITECTURE.md)** (15 min de lecture)

### Je veux installer sans Docker
👉 Lisez **[INSTALLATION.md](INSTALLATION.md)** (20 min)

### Je veux tout savoir
👉 Lisez **[README.md](README.md)** (30 min de lecture)

### J'ai des problèmes avec Docker
👉 Lisez **[DOCKER.md](DOCKER.md)** - Section "Dépannage"

### Je veux voir la structure du projet
👉 Consultez **[TREE_STRUCTURE.txt](TREE_STRUCTURE.txt)**

---

## 🎯 Premiers pas dans l'application

### 1. Créer votre compte
- Cliquez sur "S'inscrire"
- Remplissez le formulaire
- Connectez-vous

### 2. Lancer votre premier audit
- Cliquez sur "Nouvel audit"
- Entrez : `https://www.example.com`
- Cliquez sur "Lancer l'audit"
- Attendez 2-3 minutes

### 3. Explorer les résultats
- Résultats détaillés par criticité
- Recommandations de correction
- Exemples de code
- Statistiques RGAA (106 critères)

### 4. Exporter en PDF
- Bouton "Exporter PDF" en haut à droite
- Rapport professionnel complet

---

## 🛠 Commandes utiles

```bash
make help           # Voir toutes les commandes
make up             # Démarrer l'app
make down           # Arrêter l'app
make logs           # Voir les logs
make shell          # Accéder au conteneur
make db-reset       # Réinitialiser la BDD
```

---

## 🐛 Problèmes courants

### Port 8080 déjà utilisé
```bash
# Dans .env.docker.local
HTTP_PORT=8081
```

### L'audit échoue
```bash
make playwright-install
```

### Erreur base de données
```bash
make db-reset
```

### Tout nettoyer et recommencer
```bash
docker compose down -v
make start
```

---

## 📊 Ce que contient ce projet

✅ **Application complète** (50+ fichiers)
- Backend Symfony 6.4 (PHP 8.2)
- Frontend Bootstrap 5 + Twig
- Scripts Node.js (Playwright + Pa11y)
- Intégration Google Gemini AI

✅ **Docker ready** (3 services)
- nginx (serveur web)
- php (PHP-FPM + Node.js)
- mysql (base de données)

✅ **Documentation complète** (10 fichiers, 2000+ lignes)
- Guides de démarrage
- Documentation technique
- Architecture détaillée
- Dépannage complet

---

## 🎓 Technologies utilisées

**Backend**
- Symfony 6.4
- Doctrine ORM
- Symfony Security

**Frontend**
- Twig
- Bootstrap 5
- Chart.js

**Audit**
- Playwright
- Pa11y
- Google Gemini API

**Infrastructure**
- Docker + Docker Compose
- Nginx
- MySQL 8.0

---

## ✨ Fonctionnalités

✅ Authentification (inscription/connexion)
✅ Lancement automatique d'audits
✅ Tests Playwright (interactivité)
✅ Analyse Pa11y (HTML/CSS)
✅ Analyse IA (Gemini)
✅ Résultats détaillés avec accordions
✅ Statistiques RGAA (106 critères)
✅ Graphiques d'évolution
✅ Export PDF professionnel
✅ Historique complet
✅ Comparaison d'audits
✅ Dashboard avec stats

---

## 🗂 Organisation des fichiers

```
📦 rgaa-audit-app/
├── 📚 Documentation/     # 10 fichiers .md
├── 🐳 docker/           # Configuration Docker
├── ⚙️ config/           # Configuration Symfony
├── 💻 src/              # Code source PHP
├── 🎨 templates/        # Templates Twig
├── 🤖 audit-scripts/    # Scripts Node.js
└── 🌐 public/           # Assets publics
```

Voir [TREE_STRUCTURE.txt](TREE_STRUCTURE.txt) pour le détail complet.

---

## 📞 Besoin d'aide ?

### Documentation
1. [QUICKSTART.md](QUICKSTART.md) - Démarrage rapide
2. [README.md](README.md) - Documentation complète
3. [DOCKER.md](DOCKER.md) - Guide Docker
4. [INSTALLATION.md](INSTALLATION.md) - Installation manuelle
5. [ARCHITECTURE.md](ARCHITECTURE.md) - Architecture

### Logs
```bash
make logs              # Tous les logs
make logs-php          # Logs PHP
make logs-nginx        # Logs Nginx
```

### Commandes de diagnostic
```bash
docker compose ps      # État des conteneurs
make stats            # Utilisation ressources
docker compose config # Vérifier la config
```

---

## 🎉 Prêt à commencer !

1. ✅ Configurez `.env.docker.local`
2. ✅ Lancez `make start`
3. ✅ Ouvrez http://localhost:8080
4. ✅ Créez votre compte
5. ✅ Lancez votre premier audit !

**Bonne utilisation !** 🚀

---

**Questions ?** Consultez la [documentation complète](README.md) ou les [guides spécifiques](#-quelle-documentation-lire-).
