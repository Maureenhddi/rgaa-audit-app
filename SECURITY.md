# 🔒 Sécurité - RGAA Audit Application

## ⚠️ IMPORTANT - Action requise immédiatement

### Clé API Gemini compromise

Une clé API Google Gemini a été accidentellement commitée dans le dépôt Git dans le fichier `.env.docker.local`.

**Clé exposée** : `AIzaSyCiqo0ey6kWIKghjITCRLc1trgmWLpDgPI`

### Actions à effectuer IMMÉDIATEMENT

1. **Révoquer la clé compromise**
   - Aller sur : https://makersuite.google.com/app/apikey
   - Ou : https://console.cloud.google.com/apis/credentials
   - Supprimer la clé : `AIzaSyCiqo0ey6kWIKghjITCRLc1trgmWLpDgPI`

2. **Créer une nouvelle clé API**
   - Générer une nouvelle clé sur Google Cloud Console
   - La copier dans votre fichier `.env.docker.local` (NON versionné)

3. **Vérifier les usages**
   - Consulter les logs d'utilisation de l'ancienne clé
   - Vérifier qu'il n'y a pas eu d'utilisation frauduleuse

---

## 🛡️ Bonnes pratiques de sécurité

### Fichiers à NE JAMAIS commiter

Les fichiers suivants ne doivent **JAMAIS** être versionnés dans Git :

- ✅ `.env.docker.local` (déjà dans .gitignore)
- ✅ `.env.docker.production.local` (déjà dans .gitignore)
- ✅ `.env.local` (déjà dans .gitignore)
- ✅ `*.sql` / `*.sql.gz` (sauvegardes BDD)
- ✅ Tout fichier contenant des secrets, mots de passe, clés API

### Fichiers templates (SANS secrets)

Ces fichiers peuvent être versionnés car ils contiennent des valeurs d'exemple :

- `.env.docker` (template de base)
- `.env.docker.local.example` (exemple pour local)
- `.env.docker.production` (template pour production)

### Configuration locale

Après avoir cloné le projet :

```bash
# Copier le template
cp .env.docker.local.example .env.docker.local

# Éditer avec vos vraies valeurs
nano .env.docker.local

# Ajouter votre vraie clé API Gemini
GEMINI_API_KEY=votre_vraie_cle_ici
```

### Configuration production

Sur le serveur de production :

```bash
# Copier le template
cp .env.docker.production .env.docker.production.local

# Éditer avec vos valeurs de production
nano .env.docker.production.local

# Générer un secret sécurisé
php -r "echo bin2hex(random_bytes(32));"
# Copier le résultat dans APP_SECRET

# Ajouter des mots de passe forts
# Ajouter votre clé API Gemini
```

---

## 🔑 Rotation des secrets

Il est recommandé de changer régulièrement :

- **APP_SECRET** : Tous les 6 mois (invalide les sessions actives)
- **Mots de passe BDD** : Tous les 3-6 mois
- **Clés API** : En cas de suspicion de compromission

---

## 📋 Checklist avant chaque commit

Avant de faire un `git commit`, vérifier :

- [ ] Aucun fichier `.env.*.local` n'est ajouté
- [ ] Aucune clé API / mot de passe dans le code
- [ ] Aucun fichier de sauvegarde SQL
- [ ] Vérifier avec `git status` et `git diff`

---

## 🚨 En cas de secret exposé

1. **Révoquer immédiatement** le secret compromis
2. **Générer un nouveau** secret
3. **Mettre à jour** tous les environnements
4. **Vérifier les logs** pour détecter des utilisations suspectes
5. **Documenter** l'incident

---

## 📞 Contact

Pour toute question de sécurité, contacter l'administrateur du projet.

**Dernière mise à jour** : 13 novembre 2025
