# RGAA Audit Scripts

Scripts Node.js pour les audits d'accessibilité RGAA utilisant Playwright et Pa11y.

## Installation

```bash
cd audit-scripts
npm install
npm run install-browsers
```

## Scripts disponibles

### playwright-audit.js

Teste l'interactivité et la navigation au clavier :
- Navigation au clavier
- Gestion du focus
- Éléments interactifs
- Contenu dynamique
- Accessibilité des formulaires
- Liens d'évitement

```bash
node playwright-audit.js https://example.com
```

### pa11y-audit.js

Analyse le code HTML/CSS pour la conformité WCAG :
- Validation HTML
- Contraste des couleurs
- Structure sémantique
- Attributs ARIA
- Alternatives textuelles

```bash
node pa11y-audit.js https://example.com
```

## Format de sortie

Les deux scripts retournent du JSON structuré avec :
- URL testée
- Timestamp
- Liste des problèmes détectés
- Statistiques par sévérité
- Informations contextuelles

## Utilisation depuis Symfony

Ces scripts sont appelés automatiquement par les services Symfony :
- `PlaywrightService`
- `Pa11yService`

Ne les exécutez pas manuellement sauf pour le débogage.
