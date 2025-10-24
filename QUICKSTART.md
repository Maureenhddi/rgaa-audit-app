# 🚀 Démarrage rapide - RGAA Audit

Guide ultra-rapide pour démarrer l'application en 5 minutes avec Docker.

## ⚡ Installation en 4 étapes

### 1️⃣ Vérifier les prérequis

```bash
docker --version        # Doit afficher 20.10+
docker compose version  # Doit afficher 2.0+
```

Si Docker n'est pas installé : https://docs.docker.com/get-docker/

### 2️⃣ Configurer l'environnement

```bash
cd rgaa-audit-app

# Copier le fichier de configuration
cp .env.docker .env.docker.local
```

**Éditez `.env.docker.local` :**

```bash
nano .env.docker.local
# ou
code .env.docker.local
```

**Changez AU MINIMUM ces valeurs :**

```env
# 🔑 OBLIGATOIRE : Votre clé API Gemini
GEMINI_API_KEY=votre_cle_api_ici

# 🔒 Sécurité (changez les mots de passe !)
MYSQL_ROOT_PASSWORD=un_mot_de_passe_securise
MYSQL_PASSWORD=un_autre_mot_de_passe_securise
APP_SECRET=une_chaine_aleatoire_de_32_caracteres_minimum
```

**Comment obtenir une clé Gemini ?**
1. Allez sur https://makersuite.google.com/app/apikey
2. Connectez-vous avec Google
3. Cliquez sur "Create API Key"
4. Copiez la clé dans `.env.docker.local`

### 3️⃣ Démarrer l'application

**Option A - Avec Make (recommandé) :**
```bash
make start
```

**Option B - Sans Make :**
```bash
docker compose build
docker compose up -d
```

⏱️ **Attendez 2-3 minutes** que tout démarre...

### 4️⃣ Ouvrir l'application

🌐 Accédez à : **http://localhost:8080**

Vous devriez voir la page de connexion !

## 👤 Créer votre compte

1. Sur la page de connexion, cliquez sur **"S'inscrire"**
2. Remplissez le formulaire :
   - Nom : Votre nom
   - Email : votre@email.com
   - Mot de passe : minimum 6 caractères
3. Acceptez les conditions
4. Cliquez sur **"S'inscrire"**
5. Connectez-vous avec vos identifiants

## 🎯 Lancer votre premier audit

1. Une fois connecté, cliquez sur **"Nouvel audit"**
2. Entrez une URL de test, par exemple : `https://www.example.com`
3. Cliquez sur **"Lancer l'audit"**
4. ⏱️ Attendez 2-3 minutes...
5. 🎉 Consultez les résultats détaillés !

## ✅ Vérifier que tout fonctionne

```bash
# Voir l'état des services
docker compose ps

# Tous doivent être "Up" (healthy pour db)

# Voir les logs si problème
docker compose logs -f
```

## 🔧 Commandes utiles

```bash
# Voir les logs
make logs              # ou : docker compose logs -f

# Arrêter l'application
make down              # ou : docker compose down

# Redémarrer
make restart           # ou : docker compose restart

# Accéder au conteneur PHP
make shell             # ou : docker compose exec php bash

# Liste complète des commandes
make help
```

## 🐛 Problèmes courants

### Port 8080 déjà utilisé

**Solution :** Changez le port dans `.env.docker.local`
```env
HTTP_PORT=8081  # Au lieu de 8080
```
Puis : `make restart`

### Base de données non accessible

```bash
# Recréer la base
make db-reset
```

### Les audits échouent

```bash
# Réinstaller Playwright
make playwright-install
```

### Erreur "GEMINI_API_KEY"

Vérifiez que vous avez bien configuré la clé dans `.env.docker.local`

## 📚 Documentation complète

- **Installation Docker** : [DOCKER.md](DOCKER.md)
- **Installation manuelle** : [INSTALLATION.md](INSTALLATION.md)
- **Documentation complète** : [README.md](README.md)

## 🆘 Besoin d'aide ?

```bash
# Voir les logs détaillés
docker compose logs -f php

# Vérifier la configuration
docker compose config

# Tout supprimer et recommencer
docker compose down -v
make start
```

## 🎉 Et après ?

Une fois que tout fonctionne :

1. **Auditez vos sites** : Lancez des audits sur vos URLs
2. **Consultez le dashboard** : Visualisez l'évolution de la conformité
3. **Exportez en PDF** : Générez des rapports professionnels
4. **Comparez les audits** : Suivez vos améliorations dans le temps

---

**Temps d'installation total : 5 minutes** ⏱️

**Questions ?** Consultez [DOCKER.md](DOCKER.md) pour plus de détails.
