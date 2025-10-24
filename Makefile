# Makefile pour RGAA Audit Application
# Facilite l'utilisation des commandes Docker et Symfony

.PHONY: help build up down restart logs shell db-create db-migrate test clean install

# Couleurs pour les messages
BLUE = \033[0;34m
GREEN = \033[0;32m
RED = \033[0;31m
NC = \033[0m # No Color

help: ## Affiche cette aide
	@echo "$(BLUE)RGAA Audit - Commandes disponibles :$(NC)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(NC) %s\n", $$1, $$2}'

# Installation et setup
install: ## Installation complète (première utilisation)
	@echo "$(BLUE)Installation de l'application...$(NC)"
	cp .env.docker .env.docker.local
	@echo "$(GREEN)✓ Fichier .env.docker.local créé$(NC)"
	@echo "$(RED)⚠ N'oubliez pas de configurer .env.docker.local avant de continuer !$(NC)"
	@echo "  - Définissez GEMINI_API_KEY"
	@echo "  - Changez les mots de passe MySQL"
	@echo "  - Définissez APP_SECRET"

build: ## Construire les images Docker
	@echo "$(BLUE)Construction des images Docker...$(NC)"
	docker compose build
	@echo "$(GREEN)✓ Images construites$(NC)"

up: ## Démarrer tous les services
	@echo "$(BLUE)Démarrage des services...$(NC)"
	docker compose up -d
	@echo "$(GREEN)✓ Services démarrés$(NC)"
	@echo "Application disponible sur : http://localhost:8080"

down: ## Arrêter tous les services
	@echo "$(BLUE)Arrêt des services...$(NC)"
	docker compose down
	@echo "$(GREEN)✓ Services arrêtés$(NC)"

restart: down up ## Redémarrer tous les services

logs: ## Afficher les logs en temps réel
	docker compose logs -f

logs-php: ## Afficher les logs PHP uniquement
	docker compose logs -f php

logs-nginx: ## Afficher les logs Nginx uniquement
	docker compose logs -f nginx

logs-db: ## Afficher les logs de la base de données
	docker compose logs -f db

# Accès aux conteneurs
shell: ## Ouvrir un shell dans le conteneur PHP
	docker compose exec php bash

shell-db: ## Ouvrir un shell MySQL
	docker compose exec db mysql -u rgaa_user -prgaa_password rgaa_audit

# Base de données
db-create: ## Créer la base de données
	@echo "$(BLUE)Création de la base de données...$(NC)"
	docker compose exec php php bin/console doctrine:database:create --if-not-exists
	@echo "$(GREEN)✓ Base de données créée$(NC)"

db-migrate: ## Exécuter les migrations
	@echo "$(BLUE)Exécution des migrations...$(NC)"
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)✓ Migrations exécutées$(NC)"

db-reset: ## Réinitialiser la base de données (ATTENTION : supprime toutes les données !)
	@echo "$(RED)⚠ ATTENTION : Cette commande va supprimer toutes les données !$(NC)"
	@read -p "Êtes-vous sûr ? [y/N] " -n 1 -r; \
	echo; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		docker compose exec php php bin/console doctrine:database:drop --force --if-exists; \
		docker compose exec php php bin/console doctrine:database:create; \
		docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction; \
		echo "$(GREEN)✓ Base de données réinitialisée$(NC)"; \
	fi

db-backup: ## Sauvegarder la base de données
	@echo "$(BLUE)Sauvegarde de la base de données...$(NC)"
	docker compose exec db mysqldump -u rgaa_user -prgaa_password rgaa_audit > backup-$(shell date +%Y%m%d-%H%M%S).sql
	@echo "$(GREEN)✓ Base de données sauvegardée$(NC)"

# Symfony
cache-clear: ## Vider le cache Symfony
	@echo "$(BLUE)Vidage du cache...$(NC)"
	docker compose exec php php bin/console cache:clear
	@echo "$(GREEN)✓ Cache vidé$(NC)"

composer-install: ## Installer les dépendances Composer
	@echo "$(BLUE)Installation des dépendances Composer...$(NC)"
	docker compose exec php composer install
	@echo "$(GREEN)✓ Dépendances installées$(NC)"

composer-update: ## Mettre à jour les dépendances Composer
	@echo "$(BLUE)Mise à jour des dépendances Composer...$(NC)"
	docker compose exec php composer update
	@echo "$(GREEN)✓ Dépendances mises à jour$(NC)"

# Node.js / Audit scripts
npm-install: ## Installer les dépendances Node.js
	@echo "$(BLUE)Installation des dépendances Node.js...$(NC)"
	docker compose exec php bash -c "cd audit-scripts && npm install"
	@echo "$(GREEN)✓ Dépendances Node.js installées$(NC)"

playwright-install: ## Installer les navigateurs Playwright
	@echo "$(BLUE)Installation des navigateurs Playwright...$(NC)"
	docker compose exec php bash -c "cd audit-scripts && npx playwright install --with-deps chromium"
	@echo "$(GREEN)✓ Navigateurs Playwright installés$(NC)"

# Tests et qualité
test: ## Exécuter les tests
	docker compose exec php php bin/phpunit

lint: ## Vérifier la qualité du code
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

fix: ## Corriger automatiquement le code
	docker compose exec php vendor/bin/php-cs-fixer fix

# Monitoring
ps: ## Afficher l'état des conteneurs
	docker compose ps

stats: ## Afficher les statistiques des conteneurs
	docker stats

# Nettoyage
clean: ## Nettoyer les conteneurs, volumes et images
	@echo "$(RED)⚠ ATTENTION : Cette commande va supprimer tous les conteneurs et volumes !$(NC)"
	@read -p "Êtes-vous sûr ? [y/N] " -n 1 -r; \
	echo; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		docker compose down -v --rmi all; \
		echo "$(GREEN)✓ Nettoyage effectué$(NC)"; \
	fi

clean-cache: ## Nettoyer uniquement le cache Symfony
	docker compose exec php rm -rf var/cache/*
	@echo "$(GREEN)✓ Cache nettoyé$(NC)"

# Déploiement
start: build up db-create db-migrate ## Installation et démarrage complet (première utilisation)
	@echo "$(GREEN)✓ Application prête !$(NC)"
	@echo "Accédez à l'application : http://localhost:8080"

prod-build: ## Construire pour la production
	docker compose -f docker-compose.yml build --no-cache
	@echo "$(GREEN)✓ Build de production terminé$(NC)"

# Utilitaires
create-user: ## Créer un utilisateur administrateur
	@echo "$(BLUE)Création d'un utilisateur...$(NC)"
	@read -p "Email: " email; \
	read -p "Nom: " name; \
	read -sp "Mot de passe: " password; \
	echo; \
	docker compose exec php php bin/console app:create-user "$$email" "$$name" "$$password"

routes: ## Afficher toutes les routes de l'application
	docker compose exec php php bin/console debug:router

about: ## Afficher les informations sur l'application
	docker compose exec php php bin/console about
