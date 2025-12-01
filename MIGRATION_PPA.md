# Migration : Restructuration PPA et Plans Annuels

## Vue d'ensemble

Cette migration restructure le système de plans d'action pour séparer :
- **PPA (Plan Pluriannuel d'Accessibilité)** : Document stratégique
- **Plans d'Action Annuels** : Documents opérationnels avec détails techniques

## Changements apportés

### 1. Nouvelle table `annual_action_plan`
Contient les plans d'action annuels opérationnels avec tous les détails techniques.

### 2. Modifications de la table `action_plan` (PPA)
Ajout de nouveaux champs stratégiques :
- `strategic_orientations` (JSON) - Grandes orientations
- `progress_axes` (JSON) - Axes de progrès
- `annual_objectives` (JSON) - Objectifs annuels
- `resources` (JSON) - Moyens mis en œuvre
- `indicators` (JSON) - Indicateurs de suivi

### 3. Modifications de la table `action_plan_item`
- Ajout de `annual_plan_id` (clé étrangère vers `annual_action_plan`)
- `action_plan_id` devient nullable (pour rétrocompatibilité)

## Exécution de la migration

### Méthode 1 : Via Symfony CLI (recommandé)

```bash
# 1. Vérifier les migrations en attente
php bin/console doctrine:migrations:status

# 2. Exécuter la migration
php bin/console doctrine:migrations:migrate

# Ou avec confirmation automatique
php bin/console doctrine:migrations:migrate --no-interaction
```

### Méthode 2 : Via Docker (si l'application est conteneurisée)

```bash
# Entrer dans le conteneur PHP
docker exec -it rgaa-app-php bash

# Puis exécuter la migration
php bin/console doctrine:migrations:migrate --no-interaction
```

### Méthode 3 : Via MySQL directement (dernière option)

```bash
# Se connecter à MySQL
mysql -u votre_user -p votre_database

# Exécuter manuellement le SQL de la migration (voir migrations/Version20251128160000.php)
```

## Vérification post-migration

### 1. Vérifier que les tables ont été créées

```sql
-- Vérifier la nouvelle table
SHOW TABLES LIKE 'annual_action_plan';

-- Vérifier les nouvelles colonnes dans action_plan
DESCRIBE action_plan;

-- Vérifier les nouvelles colonnes dans action_plan_item
DESCRIBE action_plan_item;
```

### 2. Tester l'application

1. Accéder à une campagne d'audit
2. Générer un nouveau plan d'action
3. Vérifier que :
   - Le PPA s'affiche avec le contenu stratégique uniquement
   - Les plans annuels sont créés automatiquement
   - Les liens vers les plans annuels fonctionnent
   - Les détails techniques apparaissent dans les plans annuels

## Structure des données

### PPA (Plan Pluriannuel) - Vue stratégique
```
✅ Contient :
- Résumé stratégique (executive summary)
- Grandes orientations
- Axes de progrès
- Objectifs annuels (sans détails techniques)
- Moyens mis en œuvre
- Indicateurs de suivi

❌ NE contient PAS :
- Critères RGAA précis (ex: RGAA 1.1.1)
- Erreurs A11yLint
- Composants défaillants
- Tickets techniques
```

### Plans Annuels - Vue opérationnelle
```
✅ Contient TOUS les détails techniques :
- Critères RGAA précis (RGAA 1.1.1, RGAA 4.1.2, etc.)
- Erreurs A11yLint détaillées
- Composants défaillants
- Effort estimé par action (heures)
- Impact score
- Pages affectées
- Détails techniques de correction
- Critères d'acceptation
```

## Rollback (en cas de problème)

```bash
# Revenir à la version précédente
php bin/console doctrine:migrations:migrate prev

# Ou vers une version spécifique
php bin/console doctrine:migrations:migrate Version20251128150000
```

## Compatibilité

- ✅ **Rétrocompatible** : Les anciens plans d'action continuent de fonctionner
- ⚠️ **Nouveaux plans** : Utilisent automatiquement la nouvelle structure (PPA + Plans annuels)
- 📝 **Recommandation** : Régénérer les anciens plans pour bénéficier de la nouvelle structure

## Support

Pour toute question ou problème avec cette migration :
1. Vérifier les logs Symfony : `var/log/prod.log` ou `var/log/dev.log`
2. Vérifier les logs MySQL
3. Contacter l'équipe de développement

## Auteur

Migration créée le : 2025-11-28
Version : 20251128160000
