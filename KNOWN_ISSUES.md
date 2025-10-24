# Problèmes connus et solutions

## Export PDF temporairement désactivé

**Problème** : Le package `wkhtmltopdf` n'est pas disponible dans les dépôts Debian Trixie (utilisé par PHP 8.2-fpm).

**Impact** : L'export PDF ne fonctionnera pas tant qu'une solution n'est pas implémentée.

**Solutions possibles** :
1. Utiliser une image PHP basée sur Debian Bookworm au lieu de Trixie
2. Compiler wkhtmltopdf depuis les sources
3. Utiliser une bibliothèque PHP pure comme dompdf ou mPDF
4. Utiliser Chromium/Playwright pour générer les PDFs

**Workaround actuel** : wkhtmltopdf a été retiré du Dockerfile pour permettre le build. L'export PDF retournera du HTML pour l'instant.

## Comment réactiver l'export PDF

### Option 1 : Utiliser dompdf (recommandé - simple)

```bash
docker compose exec php composer require dompdf/dompdf
```

Puis modifier `PdfExportService.php` pour utiliser dompdf au lieu de Snappy.

### Option 2 : Utiliser Playwright (meilleure qualité)

Playwright est déjà installé dans le conteneur. On peut l'utiliser pour générer des PDFs :

```javascript
// Dans audit-scripts, créer pdf-generator.js
const { chromium } = require('playwright');
// ... générer PDF avec page.pdf()
```

### Option 3 : Downgrade vers Debian Bookworm

Modifier `docker/php/Dockerfile` :
```dockerfile
FROM php:8.2-fpm-bookworm
```

Puis installer wkhtmltopdf normalement.

## Statut actuel

✅ Application fonctionnelle à 95%
✅ Audits fonctionnels (Playwright + Pa11y + Gemini)
✅ Dashboard, historique, comparaison fonctionnels
⚠️ Export PDF temporairement en HTML
