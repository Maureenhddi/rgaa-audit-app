# Plan d'augmentation de la couverture RGAA

## ✅ État actuel FINAL MIS À JOUR

- **Critères automatisés (mapping)**: **43 critères (40.6%)**
  - Ancien: 34 critères
  - **Session 1**: +4 critères (3.1, 4.1, 10.7, 12.9) → 38 critères
  - **Session 2**: +5 critères (7.2, 10.13, 12.1, 12.10, 13.9) → 43 critères
- **Critères IA sémantique**: +3 critères (3.3, 6.1, 8.9)
- **TOTAL ACTUEL**: **46 critères sur 106 = 43.4%**
  - **Progression totale: +9 critères (de 37 à 46)**

## Objectif: Atteindre 50-60% de couverture

### ✅ Phase 1 COMPLÉTÉE: Tests Playwright additionnels → 46 critères (43.4%)

**✅ SESSION 1 (4 critères):**
- ✅ 3.1 - Information par couleur (IA contextuelle)
- ✅ 4.1 - Transcription média (IA + détection)
- ✅ 10.7 - Visibilité focus (Playwright + IA)
- ✅ 12.9 - Raccourcis clavier (détection + documentation)

**✅ SESSION 2 (5 critères):**
- ✅ 7.2 - Gestion du focus par script
- ✅ 10.13 - Contenus additionnels au survol (tooltips/popovers)
- ✅ 12.1 - Systèmes de navigation multiples
- ✅ 12.10 - Piège au clavier (modales/overlays)
- ✅ 13.9 - Contenus additionnels au focus

### Phase 2: Détection multimédia améliorée (+5 critères) → 51 critères (48.1%)

**✅ DÉJÀ AJOUTÉ:**
- ✅ 4.1 - Transcription média

**RESTANTS (5 critères):**

1. **4.2 - Sous-titres synchronisés**
    - Détecter attribut `<track kind="captions">`
    - Vérifier contrôles de sous-titres dans players

2. **4.7 - Média non temporel (audio seulement)**
    - Détecter <audio> sans contrôles visuels
    - Vérifier alternative textuelle

3. **4.9 - Transcription média temporel**
    - Similaire à 4.1 mais pour vidéo+audio synchronisés

4. **4.11 - Média temporel vidéo seulement**
    - Détecter <video> sans piste audio
    - Vérifier audiodescription ou transcription

5. **4.13 - Information sonore dans média non temporel**
    - Détecter si média contient info importante (analyse IA?)

### Phase 3: Validation HTML/CSS avancée (+4 critères) → 50 critères (47.2%)

1. **10.2 - Contenu compréhensible sans CSS**
    - Playwright peut désactiver CSS et capturer
    - IA vérifie si l'ordre de lecture est logique

2. **8.6 - Indication de la langue des passages en langue étrangère**
    - IA Gemini peut détecter les changements de langue dans le texte
    - Vérifier si attribut `lang` est présent

3. **8.8 - Respect de la restitution par les technologies d'assistance**
    - Vérifier que ARIA override HTML sémantique (aria-label sur <button>)

4. **8.10 - Utilisation de balises sémantiques HTML5**
    - Détecter <div> qui devraient être <button>, <a>, etc.
    - Vérifier présence de <main>, <nav>, <header>, <footer>

### Phase 4: Tests d'interaction IA avancés (+3 critères) → 53 critères (50%!)

1. **6.1 - Pertinence des intitulés de liens (AMÉLIORATION)**
    - Actuellement détecté par IA
    - Améliorer pour couvrir tous les cas edge

2. **10.6 - Liens visibles et identifiables**
    - IA analyse si les liens sont visuellement distinguables du texte
    - Pas seulement par la couleur

3. **9.2 - Structure du document (AMÉLIORATION)**
    - Actuellement détecte landmarks
    - Ajouter vérification de la hiérarchie globale

## Résumé des phases

| Phase | Critères ajoutés | Total | % couverture |
|-------|------------------|-------|--------------|
| **✅ ACTUEL (FAIT)** | **+9 (3.1, 4.1, 7.2, 10.7, 10.13, 12.1, 12.9, 12.10, 13.9)** | **46** | **43.4%** |
| Phase 2 (Multimédia) | +5 | 51 | 48.1% |
| Phase 3 (HTML/CSS) | +4 | 55 | 51.9% |
| Phase 4 (IA) | +3 | 58 | 54.7% |

## Effort estimé

- **✅ Quick wins FAIT**: 1 jour → 41 critères (38.7%)
- **Phase 1 restante**: 2-3 jours → 45 critères (42.5%)
- **Phase 2**: 2 jours (détection éléments multimédia + patterns) → 50 critères (47.2%)
- **Phase 3**: 2-3 jours (HTML parsing + validation) → 54 critères (50.9%)
- **Phase 4**: 1-2 jours (amélioration prompts IA) → 57 critères (53.8%)

**Total restant: ~8 jours pour atteindre 53.8% de couverture**

## ✅ Quick wins (COMPLÉTÉ)

- ✅ **3.1 - Info par couleur** (IA contextuelle ajoutée)
- ✅ **10.7 - Focus visible** (screenshot avant/après + IA)
- ✅ **4.1 - Détection multimédia** (transcription)
- ✅ **12.9 - Raccourcis clavier** (détection + documentation)

**Résultat: +4 critères ajoutés → 41 critères (38.7%)**
