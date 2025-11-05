#!/bin/bash

# Script de déploiement automatique pour RGAA Audit (Production)
# Usage: ./deploy.sh [options]
# Options:
#   --quick     Redémarrage rapide sans rebuild (pour code PHP uniquement)
#   --full      Rebuild complet (par défaut)
#   --migrate   Exécuter les migrations après le déploiement

set -e  # Arrêter en cas d'erreur

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     RGAA Audit - Déploiement Production       ║${NC}"
echo -e "${BLUE}╔════════════════════════════════════════════════╗${NC}"
echo ""

# Vérifier qu'on est dans le bon répertoire
if [ ! -f "docker-compose.prod.yml" ]; then
    echo -e "${RED}❌ Erreur: docker-compose.prod.yml non trouvé${NC}"
    echo "Veuillez exécuter ce script depuis /home/ubuntu/rgaa-audit-app"
    exit 1
fi

# Parser les arguments
QUICK_MODE=false
RUN_MIGRATIONS=false

for arg in "$@"; do
    case $arg in
        --quick)
            QUICK_MODE=true
            shift
            ;;
        --migrate)
            RUN_MIGRATIONS=true
            shift
            ;;
        --full)
            QUICK_MODE=false
            shift
            ;;
        --help)
            echo "Usage: ./deploy.sh [options]"
            echo ""
            echo "Options:"
            echo "  --quick     Redémarrage rapide sans rebuild (code PHP uniquement)"
            echo "  --full      Rebuild complet (par défaut)"
            echo "  --migrate   Exécuter les migrations de base de données"
            echo "  --help      Afficher cette aide"
            exit 0
            ;;
        *)
            echo -e "${RED}Option inconnue: $arg${NC}"
            echo "Utilisez --help pour voir les options disponibles"
            exit 1
            ;;
    esac
done

# 1. Récupérer les dernières modifications depuis Git
echo -e "${YELLOW}📥 Récupération des dernières modifications...${NC}"
git fetch origin
BEFORE_COMMIT=$(git rev-parse HEAD)
git pull origin main

AFTER_COMMIT=$(git rev-parse HEAD)

if [ "$BEFORE_COMMIT" = "$AFTER_COMMIT" ]; then
    echo -e "${GREEN}✅ Aucune nouvelle modification${NC}"
else
    echo -e "${GREEN}✅ Modifications récupérées :${NC}"
    git log --oneline "$BEFORE_COMMIT".."$AFTER_COMMIT"
    echo ""
fi

# 2. Déployer selon le mode choisi
if [ "$QUICK_MODE" = true ]; then
    echo -e "${YELLOW}⚡ Mode rapide : Redémarrage des conteneurs...${NC}"
    docker compose -f docker-compose.prod.yml --env-file .env.docker.production.local restart php web
else
    echo -e "${YELLOW}🔨 Mode complet : Reconstruction des conteneurs...${NC}"
    docker compose -f docker-compose.prod.yml --env-file .env.docker.production.local up -d --build
fi

# Attendre que les conteneurs soient prêts
echo -e "${YELLOW}⏳ Attente du démarrage des conteneurs...${NC}"
sleep 5

# 3. Vérifier que les conteneurs sont lancés
echo ""
echo -e "${YELLOW}🔍 Vérification de l'état des conteneurs...${NC}"
docker compose -f docker-compose.prod.yml --env-file .env.docker.production.local ps

# 4. Vider le cache Symfony
echo ""
echo -e "${YELLOW}🧹 Nettoyage du cache Symfony...${NC}"
docker exec rgaa_php_prod php bin/console cache:clear --no-warmup
docker exec rgaa_php_prod php bin/console cache:warmup

# 5. Exécuter les migrations si demandé
if [ "$RUN_MIGRATIONS" = true ]; then
    echo ""
    echo -e "${YELLOW}🗄️  Exécution des migrations de base de données...${NC}"
    docker exec rgaa_php_prod php bin/console doctrine:migrations:migrate --no-interaction
fi

# 6. Afficher les derniers logs
echo ""
echo -e "${YELLOW}📋 Derniers logs du conteneur PHP :${NC}"
docker logs rgaa_php_prod --tail=20

# 7. Résumé final
echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║          ✅ Déploiement terminé !               ║${NC}"
echo -e "${GREEN}╔════════════════════════════════════════════════╗${NC}"
echo ""
echo -e "${BLUE}🌐 Application accessible sur : https://access.itroom.fr${NC}"
echo ""
echo -e "${YELLOW}Commandes utiles :${NC}"
echo -e "  • Voir les logs :    ${BLUE}docker compose -f docker-compose.prod.yml logs -f${NC}"
echo -e "  • Statut :           ${BLUE}docker compose -f docker-compose.prod.yml ps${NC}"
echo -e "  • Redémarrer :       ${BLUE}docker compose -f docker-compose.prod.yml restart${NC}"
echo ""
