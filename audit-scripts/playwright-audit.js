#!/usr/bin/env node

const { chromium } = require('playwright');
const A11ylint = require('@a11ylint/core').default;
const path = require('path');

/**
 * Playwright Accessibility Audit Script
 * Tests interactivity: keyboard navigation, focus management, dynamic content
 */

async function runPlaywrightAudit(url, auditScope = 'full') {
    const browser = await chromium.launch({
        headless: true
    });

    const context = await browser.newContext({
        viewport: { width: 1920, height: 1080 }
    });

    const page = await context.newPage();
    const results = {
        url: url,
        auditScope: auditScope,
        timestamp: new Date().toISOString(),
        tests: [],
        summary: {
            passed: 0,
            failed: 0,
            warnings: 0
        },
        screenshot: null // Will contain base64 screenshot for Gemini Vision
    };

    // Setup A11yLint integration (RGAA 4.1)
    const a11ylintInstance = new A11ylint();

    // Expose A11yLint function to browser context
    await page.exposeFunction('accessibilityTesting', (document, images, frames) =>
        a11ylintInstance.run({
            mode: 'virtual',
            document,
            images,
            frames,
            customIframeBannedWords: [],
        })
    );

    // Inject browser helper script for A11yLint
    const browserHelpersPath = path.join(
        __dirname,
        'node_modules',
        '@a11ylint',
        'playwright',
        'browserHelpers',
        'browserUtils.js'
    );
    await page.addInitScript({ path: browserHelpersPath });

    try {
        // Wait for page to load with multiple strategies for better reliability
        await page.goto(url, {
            waitUntil: 'load',  // Wait for all resources (images, CSS, fonts)
            timeout: 120000  // 2 minutes for slow sites
        });

        // Additional wait for dynamic content (JS frameworks, lazy loading, etc.)
        // This ensures React/Vue/Angular apps and lazy-loaded images are rendered
        await page.waitForTimeout(3000);  // 3 seconds for dynamic content

        // Wait for network to be mostly idle (max 2 connections for 500ms)
        // This catches most late-loading resources without waiting forever
        try {
            await page.waitForLoadState('networkidle', { timeout: 10000 });
        } catch (e) {
            // If networkidle times out, continue anyway (some sites never reach it)
            console.warn('Network not idle after 10s, continuing anyway');
        }

        // Set viewport for consistent testing
        await page.setViewportSize({ width: 1280, height: 1024 });

        // Apply audit scope filtering if needed
        if (auditScope !== 'full') {
            await page.evaluate((scope) => {
                // Define selectors for transverse elements
                const transverseSelectors = [
                    'header',
                    'footer',
                    'nav',
                    '[role="navigation"]',
                    '[role="banner"]',
                    '[role="contentinfo"]',
                    '.header',
                    '.footer',
                    '.navbar',
                    '.breadcrumb',
                    '[class*="breadcrumb"]',
                    '[id*="header"]',
                    '[id*="footer"]',
                    '[id*="nav"]'
                ];

                // Define selectors for main content
                const mainContentSelectors = [
                    'main',
                    '[role="main"]',
                    '#main',
                    '#content',
                    '.main',
                    '.content',
                    '.main-content',
                    'article'
                ];

                if (scope === 'transverse') {
                    // Hide main content, keep only transverse elements
                    const mainElements = document.querySelectorAll(mainContentSelectors.join(','));
                    mainElements.forEach(el => {
                        // Check if element is not a parent of transverse elements
                        const hasTransverseChild = transverseSelectors.some(selector =>
                            el.querySelector(selector) !== null
                        );
                        if (!hasTransverseChild) {
                            el.style.display = 'none';
                            el.setAttribute('data-audit-hidden', 'true');
                        }
                    });
                } else if (scope === 'main_content') {
                    // Hide transverse elements, keep only main content
                    const transverseElements = document.querySelectorAll(transverseSelectors.join(','));
                    transverseElements.forEach(el => {
                        el.style.display = 'none';
                        el.setAttribute('data-audit-hidden', 'true');
                    });
                }
            }, auditScope);
        }

        // Capture page HTML for N/A criteria detection
        results.pageHtml = await page.content();

        // Extract DOM elements for exhaustive Gemini analysis
        results.domElements = await page.evaluate(() => {
            // Extract ALL images
            const images = Array.from(document.querySelectorAll('img')).map((img, index) => ({
                index: index,
                src: img.src,
                alt: img.alt || null,
                title: img.title || null,
                width: img.offsetWidth,
                height: img.offsetHeight,
                ariaLabel: img.getAttribute('aria-label'),
                role: img.getAttribute('role'),
                isVisible: img.offsetParent !== null,
                className: img.className,
                parentText: img.parentElement?.textContent?.slice(0, 100) || null
            })).filter(img => img.isVisible); // Only visible images

            return { images };
        });

        // Capture individual image screenshots for deep analysis
        // Only for images > 50x50px to avoid icons/spacers
        console.error('[INFO] Starting individual image capture...');
        results.individualImages = [];

        const imageElements = await page.locator('img').all();
        let capturedCount = 0;
        const maxImages = 30; // Limit to avoid timeout

        for (const imgElement of imageElements) {
            if (capturedCount >= maxImages) {
                console.error(`[INFO] Reached max image limit (${maxImages}), skipping remaining images`);
                break;
            }

            try {
                const box = await imgElement.boundingBox();
                const alt = await imgElement.getAttribute('alt');
                const src = await imgElement.getAttribute('src');

                // Skip small images (icons, spacers), hidden images, or already processed
                if (!box || box.width < 50 || box.height < 50) {
                    continue;
                }

                // Check if image is visible in viewport
                const isVisible = await imgElement.isVisible();
                if (!isVisible) {
                    continue;
                }

                // Capture screenshot of this specific image
                const imgScreenshot = await imgElement.screenshot({
                    type: 'jpeg',
                    quality: 60
                });

                const imgBase64 = imgScreenshot.toString('base64');
                const imgSizeKB = Math.round(imgBase64.length / 1024);

                results.individualImages.push({
                    index: capturedCount,
                    src: src || 'unknown',
                    alt: alt || '',
                    width: Math.round(box.width),
                    height: Math.round(box.height),
                    screenshot: imgBase64,
                    sizeKB: imgSizeKB
                });

                capturedCount++;
                console.error(`[DEBUG] Captured image ${capturedCount}: ${src?.substring(0, 50)}... (${imgSizeKB}KB)`);

            } catch (error) {
                console.error(`[WARNING] Failed to capture image: ${error.message}`);
            }
        }

        console.error(`[INFO] Captured ${capturedCount} individual images for deep analysis`);

        // Capture form screenshots for deep analysis
        console.error(`[INFO] Capturing form screenshots...`);
        results.formScreenshots = [];
        const forms = await page.locator('form').all();

        for (let i = 0; i < Math.min(forms.length, 5); i++) {
            try {
                const form = forms[i];
                const formBox = await form.boundingBox();

                if (!formBox || formBox.width < 50 || formBox.height < 50) {
                    continue;
                }

                // Scroll form into view
                await form.scrollIntoViewIfNeeded();
                await page.waitForTimeout(200);

                const formScreenshot = await form.screenshot({
                    type: 'jpeg',
                    quality: 70
                });

                const formBase64 = formScreenshot.toString('base64');
                const formSizeKB = Math.round(formBase64.length / 1024);

                // Get form fields info
                const fields = await form.locator('input, select, textarea').all();
                const fieldsInfo = [];

                for (const field of fields) {
                    const type = await field.getAttribute('type');
                    const id = await field.getAttribute('id');
                    const name = await field.getAttribute('name');
                    const labelFor = id ? await page.locator(`label[for="${id}"]`).count() : 0;

                    fieldsInfo.push({
                        type: type || 'text',
                        id: id || '',
                        name: name || '',
                        hasLabel: labelFor > 0
                    });
                }

                results.formScreenshots.push({
                    index: i,
                    screenshot: formBase64,
                    sizeKB: formSizeKB,
                    fieldsCount: fields.length,
                    fields: fieldsInfo
                });

                console.error(`[DEBUG] Captured form ${i + 1}: ${fields.length} fields (${formSizeKB}KB)`);

            } catch (error) {
                console.error(`[WARNING] Failed to capture form ${i + 1}: ${error.message}`);
            }
        }

        console.error(`[INFO] Captured ${results.formScreenshots.length} forms for deep analysis`);

        // Extract contextual elements for hybrid AI analysis
        console.error('[INFO] Extracting contextual elements for AI analysis...');
        results.contextualElements = await extractContextForIA(page);
        console.error(`[INFO] Extracted:
  - ${results.contextualElements.lowContrastElements?.length || 0} low-contrast elements
  - ${results.contextualElements.headingsWithContext?.length || 0} headings
  - ${results.contextualElements.linksWithSurroundings?.length || 0} ambiguous links
  - ${results.contextualElements.complexTables?.length || 0} complex tables
  - ${results.contextualElements.colorBasedElements?.length || 0} color-based elements
  - ${results.contextualElements.interactiveElements?.length || 0} interactive elements (focus test)
  - ${results.contextualElements.mediaElements?.length || 0} media elements
  - ${results.contextualElements.keyboardShortcuts?.length || 0} keyboard shortcuts
  - ${results.contextualElements.dynamicElements?.length || 0} dynamic elements (focus management)
  - ${results.contextualElements.modalsOverlays?.length || 0} modals/overlays (keyboard trap)
  - ${results.contextualElements.tooltipsPopovers?.length || 0} tooltips/popovers
  - ${results.contextualElements.navigationSystems?.length || 0} navigation systems`);

        // Test 1: Keyboard Navigation
        const keyboardTest = await testKeyboardNavigation(page);
        results.tests.push(keyboardTest);
        updateSummary(results.summary, keyboardTest);

        // Test 2: Focus Management
        const focusTest = await testFocusManagement(page);
        results.tests.push(focusTest);
        updateSummary(results.summary, focusTest);

        // Test 3: Interactive Elements
        const interactiveTest = await testInteractiveElements(page);
        results.tests.push(interactiveTest);
        updateSummary(results.summary, interactiveTest);

        // Test 4: Dynamic Content
        const dynamicTest = await testDynamicContent(page);
        results.tests.push(dynamicTest);
        updateSummary(results.summary, dynamicTest);

        // Test 5: Form Accessibility
        const formTest = await testFormAccessibility(page);
        results.tests.push(formTest);
        updateSummary(results.summary, formTest);

        // Test 6: Skip Links
        const skipLinksTest = await testSkipLinks(page);
        results.tests.push(skipLinksTest);
        updateSummary(results.summary, skipLinksTest);

        // Test 7: Axe-core Full Page Analysis
        const axeTest = await testAxeCore(page);
        results.tests.push(axeTest);
        updateSummary(results.summary, axeTest);

        // Test 8: A11yLint RGAA Analysis (French accessibility standards)
        const a11ylintTest = await testA11yLint(page);
        results.tests.push(a11ylintTest);
        updateSummary(results.summary, a11ylintTest);

        // Test 9: Presentation and Layout (RGAA 10.x)
        const presentationTest = await testPresentation(page);
        results.tests.push(presentationTest);
        updateSummary(results.summary, presentationTest);

        // Test 10: Enhanced Navigation (RGAA 12.x)
        const navigationEnhancedTest = await testNavigationEnhanced(page);
        results.tests.push(navigationEnhancedTest);
        updateSummary(results.summary, navigationEnhancedTest);

        // Test 11: Enhanced Forms Validation (RGAA 11.10, 11.11)
        const formsEnhancedTest = await testFormsEnhanced(page);
        results.tests.push(formsEnhancedTest);
        updateSummary(results.summary, formsEnhancedTest);

        // Test 12: Script Context Changes (RGAA 7.4)
        const scriptContextTest = await testScriptContextChanges(page);
        results.tests.push(scriptContextTest);
        updateSummary(results.summary, scriptContextTest);

    } catch (error) {
        results.error = error.message;
    } finally {
        await browser.close();
    }

    return results;
}

/**
 * Extract contextual elements for hybrid AI analysis
 * Combines Playwright technical detection with context capture for AI
 */
async function extractContextForIA(page) {
    const context = {
        lowContrastElements: [],
        headingsWithContext: [],
        linksWithSurroundings: [],
        complexTables: []
    };

    try {
        // 1. LOW CONTRAST ELEMENTS (borderline cases for AI review)
        // Playwright detects exact ratios, AI analyzes visual perception on complex backgrounds
        const lowContrastData = await page.evaluate(() => {
            const elements = [];
            const textNodes = document.querySelectorAll('p, span, a, button, h1, h2, h3, h4, h5, h6, li, td, th, label');

            textNodes.forEach((el, index) => {
                if (index >= 20) return; // Limit to first 20 for performance

                const style = window.getComputedStyle(el);
                const color = style.color;
                const bgColor = style.backgroundColor;
                const text = el.textContent.trim();

                // Skip empty or very short text
                if (!text || text.length < 3) return;

                // Calculate approximate contrast (simplified)
                const rgb = color.match(/\d+/g);
                const bgRgb = bgColor.match(/\d+/g);

                if (!rgb || !bgRgb) return;

                // Simple luminance calculation
                const lum = (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255;
                const bgLum = (0.299 * bgRgb[0] + 0.587 * bgRgb[1] + 0.114 * bgRgb[2]) / 255;
                const contrast = (Math.max(lum, bgLum) + 0.05) / (Math.min(lum, bgLum) + 0.05);

                // Flag elements with borderline contrast (3.5-5.0 range)
                // Below 3.5 is clearly bad, above 5.0 is clearly good
                // This range needs visual AI verification on complex backgrounds
                if (contrast >= 3.5 && contrast < 5.0) {
                    const rect = el.getBoundingClientRect();

                    elements.push({
                        index: index,
                        selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className ? '.' + el.className.split(' ')[0] : ''),
                        text: text.substring(0, 100),
                        contrast: Math.round(contrast * 100) / 100,
                        color: color,
                        backgroundColor: bgColor,
                        fontSize: style.fontSize,
                        fontWeight: style.fontWeight,
                        x: Math.round(rect.x),
                        y: Math.round(rect.y),
                        width: Math.round(rect.width),
                        height: Math.round(rect.height)
                    });
                }
            });

            return elements;
        });

        // Capture screenshots of low contrast elements
        for (const elData of lowContrastData.slice(0, 10)) { // Max 10 elements
            try {
                // Try to locate element by its position (more reliable than complex selectors)
                const element = await page.locator('body').first();
                const screenshot = await element.screenshot({
                    type: 'jpeg',
                    quality: 70,
                    clip: {
                        x: Math.max(0, elData.x - 20), // Add padding for context
                        y: Math.max(0, elData.y - 20),
                        width: elData.width + 40,
                        height: elData.height + 40
                    }
                });

                context.lowContrastElements.push({
                    ...elData,
                    screenshot: screenshot.toString('base64')
                });
            } catch (error) {
                console.error(`[WARNING] Failed to capture contrast element: ${error.message}`);
            }
        }

        // 2. HEADINGS WITH CONTEXT (semantic relevance)
        // Playwright detects structure, AI analyzes if content matches heading purpose
        const headingsData = await page.evaluate(() => {
            const headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
            const results = [];

            headings.forEach((heading, index) => {
                if (index >= 15) return; // Limit to first 15

                const headingText = heading.textContent.trim();
                if (!headingText) return;

                // Get next sibling content (what this heading introduces)
                let nextContent = '';
                let sibling = heading.nextElementSibling;
                let contentCount = 0;

                while (sibling && contentCount < 3) {
                    const text = sibling.textContent.trim();
                    if (text) {
                        nextContent += text.substring(0, 200) + ' ';
                        contentCount++;
                    }
                    sibling = sibling.nextElementSibling;
                }

                // Get parent section context
                const section = heading.closest('section, article, main, div[role="main"]');
                const sectionClass = section ? section.className : '';

                results.push({
                    index: index,
                    level: heading.tagName.toLowerCase(),
                    text: headingText,
                    nextContent: nextContent.trim().substring(0, 300),
                    sectionContext: sectionClass,
                    selector: heading.tagName.toLowerCase() + (heading.id ? '#' + heading.id : '')
                });
            });

            return results;
        });

        context.headingsWithContext = headingsData;

        // 3. LINKS WITH SURROUNDING CONTEXT (ambiguous links)
        // Playwright detects "click here", AI analyzes if link text is meaningful in context
        const linksData = await page.evaluate(() => {
            const links = document.querySelectorAll('a[href]');
            const results = [];

            links.forEach((link, index) => {
                if (index >= 20) return; // Limit to first 20

                const linkText = link.textContent.trim();
                if (!linkText) return;

                // Focus on potentially ambiguous links
                const ambiguousPatterns = /^(lire la suite|en savoir plus|cliquez ici|voir plus|more|read more|click here|learn more)$/i;
                const isShort = linkText.length < 20;
                const isPotentiallyAmbiguous = ambiguousPatterns.test(linkText) || isShort;

                if (isPotentiallyAmbiguous) {
                    // Get surrounding context
                    let surroundingText = '';
                    let parent = link.parentElement;
                    let depth = 0;

                    while (parent && depth < 3) {
                        const text = parent.textContent.trim();
                        if (text.length > surroundingText.length) {
                            surroundingText = text;
                        }
                        parent = parent.parentElement;
                        depth++;
                    }

                    // Get aria-label or title for context
                    const ariaLabel = link.getAttribute('aria-label');
                    const title = link.getAttribute('title');

                    results.push({
                        index: index,
                        text: linkText,
                        href: link.getAttribute('href'),
                        ariaLabel: ariaLabel,
                        title: title,
                        surroundingContext: surroundingText.substring(0, 200),
                        selector: 'a' + (link.id ? '#' + link.id : '') + (link.className ? '.' + link.className.split(' ')[0] : '')
                    });
                }
            });

            return results;
        });

        context.linksWithSurroundings = linksData;

        // 4. COMPLEX TABLES (data table analysis)
        // Playwright detects missing headers, AI analyzes if headers are descriptive
        const tablesData = await page.evaluate(() => {
            const tables = document.querySelectorAll('table');
            const results = [];

            tables.forEach((table, index) => {
                if (index >= 5) return; // Limit to first 5

                const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent.trim());
                const hasHeaders = headers.length > 0;

                if (hasHeaders) {
                    // Get sample data (first 3 rows)
                    const rows = Array.from(table.querySelectorAll('tr')).slice(0, 4);
                    const sampleData = rows.map(row => {
                        return Array.from(row.querySelectorAll('td, th')).map(cell => cell.textContent.trim().substring(0, 50));
                    });

                    const rect = table.getBoundingClientRect();

                    results.push({
                        index: index,
                        headers: headers,
                        sampleData: sampleData,
                        hasCaption: table.querySelector('caption') !== null,
                        captionText: table.querySelector('caption')?.textContent.trim() || '',
                        selector: 'table' + (table.id ? '#' + table.id : ''),
                        x: Math.round(rect.x),
                        y: Math.round(rect.y),
                        width: Math.round(rect.width),
                        height: Math.round(rect.height)
                    });
                }
            });

            return results;
        });

        // Capture screenshots of complex tables
        for (const tableData of tablesData) {
            try {
                const element = await page.locator('body').first();
                const screenshot = await element.screenshot({
                    type: 'jpeg',
                    quality: 70,
                    clip: {
                        x: Math.max(0, tableData.x),
                        y: Math.max(0, tableData.y),
                        width: Math.min(tableData.width, 1200),
                        height: Math.min(tableData.height, 800)
                    }
                });

                tableData.screenshot = screenshot.toString('base64');
                context.complexTables.push(tableData);
            } catch (error) {
                console.error(`[WARNING] Failed to capture table: ${error.message}`);
            }
        }

        // 5. COLOR-BASED INFORMATION (RGAA 3.1)
        // Detect elements that may convey information only by color
        const colorBasedData = await page.evaluate(() => {
            const elements = [];

            // Look for status indicators, error messages, charts
            const candidates = document.querySelectorAll(
                '.error, .success, .warning, .status, .alert, ' +
                '[class*="red"], [class*="green"], [style*="color:red"], [style*="color:green"], ' +
                'svg, canvas, .chart, [role="img"]'
            );

            candidates.forEach((el, index) => {
                if (index >= 10) return; // Limit to 10

                const rect = el.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0) return;

                const style = window.getComputedStyle(el);
                const colors = [style.color, style.backgroundColor, style.borderColor].filter(c => c && c !== 'rgba(0, 0, 0, 0)');

                elements.push({
                    index: index,
                    type: el.tagName.toLowerCase(),
                    text: el.textContent.trim().substring(0, 100),
                    colors: colors.join(', '),
                    x: Math.round(rect.x),
                    y: Math.round(rect.y),
                    width: Math.round(rect.width),
                    height: Math.round(rect.height)
                });
            });

            return elements;
        });

        // Capture screenshots of color-based elements
        for (const elData of colorBasedData) {
            try {
                const element = await page.locator('body').first();
                const screenshot = await element.screenshot({
                    type: 'jpeg',
                    quality: 70,
                    clip: {
                        x: Math.max(0, elData.x - 10),
                        y: Math.max(0, elData.y - 10),
                        width: Math.min(elData.width + 20, 600),
                        height: Math.min(elData.height + 20, 400)
                    }
                });

                context.colorBasedElements = context.colorBasedElements || [];
                context.colorBasedElements.push({
                    ...elData,
                    screenshot: screenshot.toString('base64')
                });
            } catch (error) {
                console.error(`[WARNING] Failed to capture color element: ${error.message}`);
            }
        }

        // 6. FOCUS VISIBILITY (RGAA 10.7)
        // Capture before/after screenshots of interactive elements to analyze focus indicators
        const interactiveData = await page.evaluate(() => {
            const elements = [];
            const candidates = document.querySelectorAll('a, button, input, select, textarea');

            let count = 0;
            candidates.forEach((el) => {
                if (count >= 10) return; // Limit to 10 elements

                const rect = el.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0) return;

                elements.push({
                    index: count,
                    type: el.tagName.toLowerCase(),
                    text: el.textContent.trim().substring(0, 50) || el.value || el.placeholder || '',
                    selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className ? '.' + el.className.split(' ')[0] : ''),
                    x: Math.round(rect.x),
                    y: Math.round(rect.y),
                    width: Math.round(rect.width),
                    height: Math.round(rect.height)
                });
                count++;
            });

            return elements;
        });

        // Capture before/after focus screenshots
        for (const elData of interactiveData) {
            try {
                // Screenshot BEFORE focus
                const screenshotBefore = await page.screenshot({
                    type: 'jpeg',
                    quality: 70,
                    clip: {
                        x: Math.max(0, elData.x - 20),
                        y: Math.max(0, elData.y - 20),
                        width: elData.width + 40,
                        height: elData.height + 40
                    }
                });

                // Focus the element
                await page.evaluate((selector) => {
                    const el = document.querySelector(selector);
                    if (el) el.focus();
                }, elData.selector);

                await page.waitForTimeout(100); // Wait for focus styles to apply

                // Screenshot AFTER focus
                const screenshotAfter = await page.screenshot({
                    type: 'jpeg',
                    quality: 70,
                    clip: {
                        x: Math.max(0, elData.x - 20),
                        y: Math.max(0, elData.y - 20),
                        width: elData.width + 40,
                        height: elData.height + 40
                    }
                });

                context.interactiveElements = context.interactiveElements || [];
                context.interactiveElements.push({
                    ...elData,
                    screenshotBefore: screenshotBefore.toString('base64'),
                    screenshotAfter: screenshotAfter.toString('base64')
                });
            } catch (error) {
                console.error(`[WARNING] Failed to capture focus for element: ${error.message}`);
            }
        }

        // 7. MEDIA ELEMENTS (RGAA 4.1)
        // Detect audio/video and check for transcription
        const mediaData = await page.evaluate(() => {
            const elements = [];
            const media = document.querySelectorAll('audio, video, iframe[src*="youtube"], iframe[src*="vimeo"]');

            media.forEach((el, index) => {
                if (index >= 10) return;

                const rect = el.getBoundingClientRect();
                const tagName = el.tagName.toLowerCase();

                // Get tracks
                const tracks = Array.from(el.querySelectorAll('track')).map(t => ({
                    kind: t.getAttribute('kind'),
                    label: t.getAttribute('label'),
                    srclang: t.getAttribute('srclang')
                }));

                // Get surrounding context
                let surroundingContext = '';
                let parent = el.parentElement;
                let depth = 0;

                while (parent && depth < 3) {
                    const text = parent.textContent.trim();
                    if (text.length > surroundingContext.length && text.length < 500) {
                        surroundingContext = text;
                    }
                    parent = parent.parentElement;
                    depth++;
                }

                elements.push({
                    index: index,
                    type: tagName === 'iframe' ? 'video-embedded' : tagName,
                    tagName: tagName,
                    src: el.getAttribute('src') || el.querySelector('source')?.getAttribute('src') || '',
                    tracks: tracks.length > 0 ? JSON.stringify(tracks) : null,
                    ariaLabel: el.getAttribute('aria-label'),
                    surroundingContext: surroundingContext.substring(0, 300),
                    x: Math.round(rect.x),
                    y: Math.round(rect.y),
                    width: Math.round(rect.width),
                    height: Math.round(rect.height)
                });
            });

            return elements;
        });

        // Capture screenshots of media elements
        for (const mediaEl of mediaData) {
            try {
                const screenshot = await page.screenshot({
                    type: 'jpeg',
                    quality: 70,
                    clip: {
                        x: Math.max(0, mediaEl.x - 30),
                        y: Math.max(0, mediaEl.y - 30),
                        width: Math.min(mediaEl.width + 60, 800),
                        height: Math.min(mediaEl.height + 60, 600)
                    }
                });

                context.mediaElements = context.mediaElements || [];
                context.mediaElements.push({
                    ...mediaEl,
                    screenshot: screenshot.toString('base64')
                });
            } catch (error) {
                console.error(`[WARNING] Failed to capture media element: ${error.message}`);
            }
        }

        // 8. KEYBOARD SHORTCUTS (RGAA 12.9)
        // Detect keyboard event listeners
        const keyboardShortcutsData = await page.evaluate(() => {
            const shortcuts = [];

            // Find elements with keyboard event listeners
            const elementsWithListeners = document.querySelectorAll('[onkeydown], [onkeyup], [onkeypress]');

            elementsWithListeners.forEach((el, index) => {
                if (index >= 10) return;

                shortcuts.push({
                    index: index,
                    key: 'detected (inline handler)',
                    targetElement: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                    action: 'keyboard event handler found',
                    ariaKeyshortcuts: el.getAttribute('aria-keyshortcuts'),
                    pageContext: document.querySelector('[aria-label*="help"], [aria-label*="aide"], [title*="shortcuts"], [title*="raccourcis"]') ? 'help section found' : 'no help section'
                });
            });

            // Check for aria-keyshortcuts attribute (better practice)
            const ariaShortcuts = document.querySelectorAll('[aria-keyshortcuts]');
            ariaShortcuts.forEach((el, index) => {
                if (shortcuts.length >= 15) return;

                shortcuts.push({
                    index: shortcuts.length,
                    key: el.getAttribute('aria-keyshortcuts'),
                    targetElement: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                    action: el.getAttribute('aria-label') || 'no description',
                    ariaKeyshortcuts: el.getAttribute('aria-keyshortcuts'),
                    pageContext: 'documented with aria-keyshortcuts'
                });
            });

            return shortcuts;
        });

        context.keyboardShortcuts = keyboardShortcutsData;

        // 9. DYNAMIC ELEMENTS WITH FOCUS MANAGEMENT (RGAA 7.2)
        const dynamicElementsData = await page.evaluate(() => {
            const elements = [];
            // Detect modals, dialogs, dropdowns that appear dynamically
            const candidates = document.querySelectorAll('[role="dialog"], [role="alertdialog"], .modal, .dropdown-menu, [aria-haspopup="true"]');

            candidates.forEach((el, index) => {
                if (index >= 10) return;

                const trigger = el.previousElementSibling || el.parentElement?.querySelector('button, a');

                elements.push({
                    index: index,
                    type: el.getAttribute('role') || el.className || 'dynamic element',
                    selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                    trigger: trigger ? trigger.tagName.toLowerCase() + (trigger.id ? '#' + trigger.id : '') : 'unknown',
                    focusAfterOpen: 'to be tested',
                    focusAfterClose: 'to be tested',
                    ariaAttributes: [el.getAttribute('role'), el.getAttribute('aria-modal'), el.getAttribute('aria-labelledby')].filter(Boolean).join(', ') || 'none'
                });
            });

            return elements;
        });

        context.dynamicElements = dynamicElementsData;

        // 10. MODALS/OVERLAYS FOR KEYBOARD TRAP DETECTION (RGAA 12.10)
        const modalsData = await page.evaluate(() => {
            const elements = [];
            const modals = document.querySelectorAll('[role="dialog"], [role="alertdialog"], .modal, [aria-modal="true"]');

            modals.forEach((el, index) => {
                if (index >= 10) return;

                const closeButton = el.querySelector('[aria-label*="close"], [aria-label*="fermer"], .close, button.close');

                elements.push({
                    index: index,
                    type: el.getAttribute('role') || 'modal',
                    selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                    canTabOut: 'to be tested with Playwright',
                    escCloses: 'to be tested with Playwright',
                    closeButtonVisible: closeButton ? 'yes' : 'no',
                    role: el.getAttribute('role') || 'none',
                    ariaModal: el.getAttribute('aria-modal') || 'not set'
                });
            });

            return elements;
        });

        context.modalsOverlays = modalsData;

        // 11. TOOLTIPS/POPOVERS (RGAA 10.13, 13.9)
        const tooltipsData = await page.evaluate(() => {
            const elements = [];
            // Detect tooltips, popovers
            const candidates = document.querySelectorAll('[role="tooltip"], [title], .tooltip, .popover, [aria-describedby], [data-toggle="tooltip"]');

            candidates.forEach((el, index) => {
                if (index >= 10) return;

                const title = el.getAttribute('title');
                const tooltip = title || el.textContent.trim().substring(0, 50);

                if (tooltip) {
                    elements.push({
                        index: index,
                        type: el.getAttribute('role') === 'tooltip' ? 'tooltip' : 'element-with-tooltip',
                        trigger: el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                        displayMethod: el.getAttribute('data-toggle') || el.getAttribute('aria-haspopup') || 'hover/focus',
                        dismissibleEsc: 'to be tested',
                        persistsOnHover: 'to be tested',
                        keyboardAccessible: el.tabIndex >= 0 ? 'yes' : 'no'
                    });
                }
            });

            return elements;
        });

        context.tooltipsPopovers = tooltipsData;

        // 12. NAVIGATION SYSTEMS (RGAA 12.1)
        const navigationSystemsData = await page.evaluate(() => {
            const systems = [];

            // 1. Main navigation
            const navs = document.querySelectorAll('nav, [role="navigation"]');
            if (navs.length > 0) {
                systems.push({
                    index: 0,
                    type: 'main-navigation',
                    selector: 'nav',
                    description: navs.length + ' navigation menu(s) found',
                    visible: 'yes'
                });
            }

            // 2. Search
            const search = document.querySelector('input[type="search"], [role="search"], form[action*="search"], input[name*="search"], input[placeholder*="search"], input[placeholder*="recherch"]');
            if (search) {
                systems.push({
                    index: systems.length,
                    type: 'search',
                    selector: search.tagName.toLowerCase() + (search.id ? '#' + search.id : ''),
                    description: 'Search input found',
                    visible: 'yes'
                });
            }

            // 3. Breadcrumb
            const breadcrumb = document.querySelector('[aria-label*="breadcrumb"], [aria-label*="fil"], .breadcrumb, nav ol, nav ul');
            if (breadcrumb && breadcrumb.textContent.includes('>') || breadcrumb?.querySelector('li')) {
                systems.push({
                    index: systems.length,
                    type: 'breadcrumb',
                    selector: breadcrumb.tagName.toLowerCase() + (breadcrumb.id ? '#' + breadcrumb.id : ''),
                    description: 'Breadcrumb navigation found',
                    visible: 'yes'
                });
            }

            // 4. Sitemap link
            const sitemapLink = document.querySelector('a[href*="sitemap"], a[href*="plan"]');
            if (sitemapLink) {
                systems.push({
                    index: systems.length,
                    type: 'sitemap',
                    selector: 'a[href*="sitemap"]',
                    description: 'Sitemap link found',
                    visible: 'yes'
                });
            }

            // 5. Table of contents (for long pages)
            const toc = document.querySelector('[role="navigation"] ul li a[href^="#"], .table-of-contents, #toc');
            if (toc) {
                systems.push({
                    index: systems.length,
                    type: 'table-of-contents',
                    selector: toc.id ? '#' + toc.id : toc.className,
                    description: 'Table of contents found',
                    visible: 'yes'
                });
            }

            return systems;
        });

        context.navigationSystems = navigationSystemsData;

    } catch (error) {
        console.error(`[ERROR] Failed to extract context for IA: ${error.message}`);
    }

    return context;
}

/**
 * Test keyboard navigation
 */
async function testKeyboardNavigation(page) {
    const test = {
        name: 'Keyboard Navigation',
        category: 'navigation',
        issues: []
    };

    try {
        // Get all focusable elements
        const focusableElements = await page.evaluate(() => {
            const selectors = 'a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])';
            const elements = document.querySelectorAll(selectors);
            return Array.from(elements).map((el, index) => ({
                tagName: el.tagName,
                id: el.id,
                class: el.className,
                tabIndex: el.tabIndex,
                index: index
            }));
        });

        if (focusableElements.length === 0) {
            test.issues.push({
                severity: 'critical',
                message: 'Aucun élément focusable trouvé sur la page',
                selector: 'body',
                context: 'Navigation au clavier impossible'
            });
        }

        // Check for negative tabindex on interactive elements
        const negativeTabIndex = await page.evaluate(() => {
            const interactive = document.querySelectorAll('a, button');
            return Array.from(interactive)
                .filter(el => el.tabIndex === -1)
                .map(el => ({
                    tagName: el.tagName,
                    selector: el.tagName + (el.id ? '#' + el.id : '') + (el.className ? '.' + el.className.split(' ')[0] : ''),
                    text: el.textContent.trim().substring(0, 50)
                }));
        });

        negativeTabIndex.forEach(el => {
            test.issues.push({
                severity: 'major',
                message: 'Élément interactif avec tabindex="-1"',
                selector: el.selector,
                context: `Élément "${el.text}" non accessible au clavier`
            });
        });

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test focus management
 */
async function testFocusManagement(page) {
    const test = {
        name: 'Focus Management',
        category: 'focus',
        issues: []
    };

    try {
        // Check focus visible styles
        const focusStyles = await page.evaluate(() => {
            const results = [];
            const focusableElements = document.querySelectorAll('a, button, input, select, textarea');

            focusableElements.forEach((el, index) => {
                if (index < 10) { // Check first 10 elements to avoid performance issues
                    el.focus();
                    const styles = window.getComputedStyle(el);
                    const outlineWidth = styles.outlineWidth;
                    const outlineStyle = styles.outlineStyle;

                    if (outlineWidth === '0px' || outlineStyle === 'none') {
                        results.push({
                            tagName: el.tagName,
                            selector: el.tagName + (el.id ? '#' + el.id : ''),
                            text: el.textContent.trim().substring(0, 30)
                        });
                    }
                }
            });

            return results;
        });

        focusStyles.forEach(el => {
            test.issues.push({
                severity: 'major',
                message: 'Indicateur de focus invisible',
                selector: el.selector,
                context: `Élément "${el.text}" sans indicateur visuel de focus`
            });
        });

        // Check focus trap in modals
        const modals = await page.locator('[role="dialog"], .modal').count();
        if (modals > 0) {
            test.issues.push({
                severity: 'minor',
                message: 'Modal détecté - vérifier le piège à focus',
                selector: '[role="dialog"]',
                context: 'Vérification manuelle requise pour le piège à focus'
            });
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test interactive elements
 */
async function testInteractiveElements(page) {
    const test = {
        name: 'Interactive Elements',
        category: 'interaction',
        issues: []
    };

    try {
        // Check for div/span used as buttons
        const fakeButtons = await page.evaluate(() => {
            const clickHandlers = document.querySelectorAll('[onclick], .clickable, .btn');
            return Array.from(clickHandlers)
                .filter(el => el.tagName === 'DIV' || el.tagName === 'SPAN')
                .map(el => ({
                    tagName: el.tagName,
                    selector: el.tagName + (el.id ? '#' + el.id : '') + (el.className ? '.' + el.className.split(' ')[0] : ''),
                    text: el.textContent.trim().substring(0, 50)
                }));
        });

        fakeButtons.forEach(el => {
            test.issues.push({
                severity: 'critical',
                message: 'Élément non-sémantique utilisé comme bouton',
                selector: el.selector,
                context: `${el.tagName} clickable devrait être un <button> : "${el.text}"`
            });
        });

        // Check buttons without accessible name
        const unnamedButtons = await page.evaluate(() => {
            const buttons = document.querySelectorAll('button, [role="button"]');
            return Array.from(buttons)
                .filter(btn => {
                    const text = btn.textContent.trim();
                    const ariaLabel = btn.getAttribute('aria-label');
                    const ariaLabelledBy = btn.getAttribute('aria-labelledby');
                    return !text && !ariaLabel && !ariaLabelledBy;
                })
                .map(btn => ({
                    tagName: btn.tagName,
                    selector: btn.tagName + (btn.id ? '#' + btn.id : ''),
                    innerHTML: btn.innerHTML.substring(0, 50)
                }));
        });

        unnamedButtons.forEach(el => {
            test.issues.push({
                severity: 'critical',
                message: 'Bouton sans nom accessible',
                selector: el.selector,
                context: `Bouton sans texte ni aria-label`
            });
        });

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test dynamic content
 */
async function testDynamicContent(page) {
    const test = {
        name: 'Dynamic Content',
        category: 'dynamic',
        issues: []
    };

    try {
        // Check for live regions
        const liveRegions = await page.evaluate(() => {
            const regions = document.querySelectorAll('[aria-live]');
            return regions.length;
        });

        // Check for status messages
        const statusElements = await page.evaluate(() => {
            const status = document.querySelectorAll('[role="status"], [role="alert"]');
            return status.length;
        });

        if (liveRegions === 0 && statusElements === 0) {
            test.issues.push({
                severity: 'minor',
                message: 'Aucune région dynamique détectée',
                selector: 'body',
                context: 'Vérifier si des mises à jour dynamiques nécessitent aria-live'
            });
        }

        test.status = test.issues.length === 0 ? 'passed' : 'warning';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test form accessibility
 */
async function testFormAccessibility(page) {
    const test = {
        name: 'Form Accessibility',
        category: 'forms',
        issues: []
    };

    try {
        // ADVANCED FORM LABEL CHECKS (RGAA 11.1)
        const labelIssues = await page.evaluate(() => {
            const inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
            const issues = [];

            inputs.forEach(input => {
                const id = input.id;
                const ariaLabel = input.getAttribute('aria-label');
                const ariaLabelledBy = input.getAttribute('aria-labelledby');
                const placeholder = input.getAttribute('placeholder');

                // Find associated label
                const label = id ? document.querySelector(`label[for="${id}"]`) : null;

                const selector = input.tagName.toLowerCase() +
                    (input.id ? '#' + input.id : '') +
                    (input.name ? '[name="' + input.name + '"]' : '');

                // 1. Check if label exists (technical)
                if (!label && !ariaLabel && !ariaLabelledBy) {
                    issues.push({
                        type: 'missing-label',
                        severity: 'critical',
                        message: 'Champ sans label associé',
                        selector: selector,
                        context: `${input.tagName} (${input.type}) - Aucun label, aria-label ou aria-labelledby`
                    });
                    return; // Skip other checks if no label at all
                }

                // 2. Check if label is visible (not hidden)
                if (label) {
                    const style = window.getComputedStyle(label);
                    const isHidden = style.display === 'none' ||
                                   style.visibility === 'hidden' ||
                                   style.opacity === '0' ||
                                   label.offsetWidth === 0 ||
                                   label.offsetHeight === 0;

                    if (isHidden) {
                        issues.push({
                            type: 'hidden-label',
                            severity: 'critical',
                            message: 'Label caché visuellement',
                            selector: selector,
                            context: `Le label existe techniquement mais n'est pas visible (display:none, visibility:hidden, etc.)`
                        });
                    }

                    // 3. Check label proximity (distance)
                    const labelRect = label.getBoundingClientRect();
                    const inputRect = input.getBoundingClientRect();

                    // Calculate distance between centers
                    const labelCenterX = labelRect.left + labelRect.width / 2;
                    const labelCenterY = labelRect.top + labelRect.height / 2;
                    const inputCenterX = inputRect.left + inputRect.width / 2;
                    const inputCenterY = inputRect.top + inputRect.height / 2;

                    const distance = Math.sqrt(
                        Math.pow(labelCenterX - inputCenterX, 2) +
                        Math.pow(labelCenterY - inputCenterY, 2)
                    );

                    // Flag if label is too far (> 300px)
                    if (distance > 300 && !isHidden) {
                        issues.push({
                            type: 'label-too-far',
                            severity: 'major',
                            message: 'Label trop éloigné du champ',
                            selector: selector,
                            context: `Distance: ${Math.round(distance)}px - Le label devrait être proche du champ (< 300px)`
                        });
                    }

                    // 4. Check for generic labels
                    const labelText = label.textContent.trim().toLowerCase();
                    const genericLabels = ['input', 'champ', 'field', 'enter', 'saisir', 'texte', 'text'];

                    if (genericLabels.includes(labelText)) {
                        issues.push({
                            type: 'generic-label',
                            severity: 'major',
                            message: 'Label trop générique',
                            selector: selector,
                            context: `Label "${label.textContent.trim()}" n'est pas descriptif`
                        });
                    }
                }

                // 5. Check for placeholder-only labeling (bad practice)
                if (placeholder && !label && !ariaLabel && !ariaLabelledBy) {
                    issues.push({
                        type: 'placeholder-only',
                        severity: 'critical',
                        message: 'Placeholder utilisé comme label',
                        selector: selector,
                        context: `Le placeholder "${placeholder}" ne remplace pas un vrai label`
                    });
                }
            });

            return issues;
        });

        // Add all detected issues
        labelIssues.forEach(issue => {
            test.issues.push(issue);
        });

        // Check required fields indication
        const requiredFields = await page.evaluate(() => {
            const required = document.querySelectorAll('input[required], select[required], textarea[required]');
            return Array.from(required)
                .filter(field => !field.getAttribute('aria-required'))
                .map(field => ({
                    selector: field.tagName + (field.id ? '#' + field.id : '')
                }));
        });

        requiredFields.forEach(field => {
            test.issues.push({
                severity: 'major',
                message: 'Champ requis sans aria-required',
                selector: field.selector,
                context: 'Ajouter aria-required="true" pour les lecteurs d\'écran'
            });
        });

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test skip links
 */
async function testSkipLinks(page) {
    const test = {
        name: 'Skip Links',
        category: 'navigation',
        issues: []
    };

    try {
        const skipLinks = await page.evaluate(() => {
            const links = document.querySelectorAll('a[href^="#"]');
            const skipPattern = /skip|aller au contenu|eviter|passer/i;
            return Array.from(links)
                .filter(link => skipPattern.test(link.textContent))
                .map(link => ({
                    text: link.textContent.trim(),
                    href: link.getAttribute('href')
                }));
        });

        if (skipLinks.length === 0) {
            test.issues.push({
                severity: 'major',
                message: 'Aucun lien d\'évitement trouvé',
                selector: 'body',
                context: 'Ajouter un lien "Aller au contenu principal" en début de page'
            });
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test avec Axe-core (remplace Pa11y)
 * Analyse complète HTML/CSS/ARIA/WCAG
 */
async function testAxeCore(page) {
    const test = {
        name: 'Axe-core Full Analysis (HTML/CSS/ARIA/WCAG)',
        category: 'static-analysis',
        issues: []
    };

    try {
        // Injecter axe-core dans la page
        await page.addScriptTag({ path: require.resolve('axe-core') });

        // Exécuter axe-core avec toutes les règles WCAG 2.1 AA
        const results = await page.evaluate(async () => {
            return await axe.run({
                runOnly: {
                    type: 'tag',
                    values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice']
                }
            });
        });

        // Mapper les violations en issues
        if (results.violations && results.violations.length > 0) {
            for (const violation of results.violations) {
                for (const node of violation.nodes) {
                    test.issues.push({
                        severity: mapAxeSeverity(violation.impact),
                        message: `${violation.id}: ${violation.description}`,
                        selector: node.target.join(', '),
                        context: node.html ? node.html.substring(0, 200) : '',
                        wcagCriteria: violation.tags.filter(tag => tag.startsWith('wcag')),
                        helpUrl: violation.helpUrl
                    });
                }
            }
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';
        test.totalViolations = results.violations ? results.violations.length : 0;
        test.totalPasses = results.passes ? results.passes.length : 0;

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Test avec A11yLint (RGAA 4.1 - Standard français)
 * Uses official @a11ylint/core library
 * Tests 6 RGAA criteria: 1.1.1, 2.1.1, 2.2.1, 8.1.1, 8.3, 8.5
 */
async function testA11yLint(page) {
    const test = {
        name: 'A11yLint RGAA Analysis',
        category: 'rgaa-specific',
        issues: []
    };

    try {
        // Extract DOM data using A11yLint browser helpers
        const a11ylintResults = await page.evaluate(() => {
            // These functions are injected by browserUtils.js
            const frames = window.A11YLINT_PLAYWRIGHT.extractFrames();
            const images = window.A11YLINT_PLAYWRIGHT.extractImages();
            const documentData = window.A11YLINT_PLAYWRIGHT.extractDocumentData();

            // Call the exposed function that runs A11yLint in Node.js context
            return window.accessibilityTesting(documentData, images, frames);
        });

        // A11yLint returns results structured by RGAA criteria:
        // {
        //   'RGAA - 1.1.1': [...],
        //   'RGAA - 2.1.1': [...],
        //   'RGAA - 2.2.1': [...],
        //   'RGAA - 8.1.1': [...],
        //   'RGAA - 8.3': [...],
        //   'RGAA - 8.5': [...]
        // }

        let totalChecks = 0;

        for (const [criteriaName, violations] of Object.entries(a11ylintResults)) {
            if (violations && Array.isArray(violations) && violations.length > 0) {
                for (let i = 0; i < violations.length; i++) {
                    const violation = violations[i];
                    // Extract RGAA criteria number (e.g., "RGAA - 1.1.1" -> "1.1.1")
                    const criteriaNumber = criteriaName.replace('RGAA - ', '');

                    // Build better selector with index as fallback
                    let selector = violation.selector || extractSelector(violation);

                    // If still unknown, add index
                    if (selector === 'unknown' || selector === 'img' || selector === 'iframe' || selector === 'frame') {
                        selector = `${selector}:nth-of-type(${i + 1})`;
                    }

                    // Build better context
                    let context = violation.outerHTML ? violation.outerHTML.substring(0, 200) : null;

                    // If no context, try to extract useful info from violation
                    if (!context && violation.src) {
                        context = `src="${violation.src}"`;
                    } else if (!context && violation.type) {
                        context = `type="${violation.type}"`;
                    }

                    test.issues.push({
                        severity: getSeverityForRGAA(criteriaNumber),
                        message: `${criteriaName}: ${violation.message || getMessageForRGAA(criteriaNumber)}`,
                        selector: selector,
                        context: context,
                        rgaaCriteria: [criteriaNumber],
                        wcagCriteria: getWCAGforRGAA(criteriaNumber)
                    });
                }
                totalChecks += violations.length;
            }
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';
        test.totalChecks = totalChecks;

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Extract selector from A11yLint violation object
 */
function extractSelector(violation) {
    if (!violation) return 'unknown';

    // Try to build selector from outerHTML
    if (violation.outerHTML) {
        // Extract tag name
        const tagMatch = violation.outerHTML.match(/<(\w+)/);
        const tag = tagMatch ? tagMatch[1] : 'unknown';

        // Try to extract ID
        const idMatch = violation.outerHTML.match(/\sid="([^"]+)"/);
        if (idMatch) {
            return `${tag}#${idMatch[1]}`;
        }

        // Try to extract class
        const classMatch = violation.outerHTML.match(/\sclass="([^"]+)"/);
        if (classMatch) {
            const firstClass = classMatch[1].trim().split(' ')[0];
            return `${tag}.${firstClass}`;
        }

        // For images, try to extract src as identifier
        if (tag === 'img') {
            const srcMatch = violation.outerHTML.match(/\ssrc="([^"]+)"/);
            if (srcMatch) {
                const src = srcMatch[1];
                // Get filename from URL
                const filename = src.split('/').pop().split('?')[0];
                return `img[src*="${filename}"]`;
            }
        }

        // For iframes, try to extract src or title
        if (tag === 'iframe' || tag === 'frame') {
            const srcMatch = violation.outerHTML.match(/\ssrc="([^"]+)"/);
            if (srcMatch) {
                const src = srcMatch[1];
                const filename = src.split('/').pop().split('?')[0];
                return `${tag}[src*="${filename}"]`;
            }
        }

        return tag;
    }

    return 'unknown';
}

/**
 * Get severity level for RGAA criteria
 */
function getSeverityForRGAA(criteria) {
    // Critical: Images alt, frames title, language, title
    if (criteria.startsWith('1.1') || criteria.startsWith('2.1') || criteria.startsWith('8.3') || criteria.startsWith('8.5')) {
        return 'critical';
    }
    // Major: Frame banned words, doctype
    if (criteria.startsWith('2.2') || criteria.startsWith('8.1')) {
        return 'major';
    }
    return 'minor';
}

/**
 * Get descriptive message for RGAA criteria
 */
function getMessageForRGAA(criteria) {
    const messages = {
        '1.1.1': 'Image sans alternative textuelle',
        '2.1.1': 'Cadre sans titre',
        '2.2.1': 'Cadre avec titre non pertinent',
        '8.1.1': 'Document sans doctype valide',
        '8.3': 'Page sans indication de langue',
        '8.5': 'Page sans titre'
    };
    return messages[criteria] || 'Violation RGAA';
}

/**
 * Map RGAA criteria to WCAG criteria
 */
function getWCAGforRGAA(criteria) {
    const mapping = {
        '1.1.1': ['1.1.1'], // Non-text Content
        '2.1.1': ['4.1.2'], // Name, Role, Value
        '2.2.1': ['4.1.2'], // Name, Role, Value
        '8.1.1': ['4.1.1'], // Parsing
        '8.3': ['3.1.1'],   // Language of Page
        '8.5': ['2.4.2']    // Page Titled
    };
    return mapping[criteria] || [];
}

/**
 * Map Axe severity to our severity levels
 */
function mapAxeSeverity(impact) {
    switch(impact) {
        case 'critical':
            return 'critical';
        case 'serious':
            return 'critical';
        case 'moderate':
            return 'major';
        case 'minor':
            return 'minor';
        default:
            return 'minor';
    }
}

/**
 * Test presentation and layout (RGAA 10.x)
 */
async function testPresentation(page) {
    const test = {
        name: 'Presentation and Layout (RGAA 10.x)',
        category: 'presentation',
        issues: []
    };

    try {
        // Store original viewport
        const originalViewport = page.viewportSize();

        // 10.4 - Text at 200% zoom (simulate by reducing viewport to half)
        await page.setViewportSize({ width: 640, height: 512 }); // Half of 1280x1024

        // Wait for potential reflow
        await page.waitForTimeout(500);

        const zoomIssues = await page.evaluate(() => {
            const hasHorizontalScroll = document.documentElement.scrollWidth > document.documentElement.clientWidth + 10;
            const scrollWidth = document.documentElement.scrollWidth;
            const clientWidth = document.documentElement.clientWidth;

            return {
                hasHorizontalScroll,
                scrollWidth,
                clientWidth
            };
        });

        if (zoomIssues.hasHorizontalScroll) {
            test.issues.push({
                severity: 'major',
                message: 'Défilement horizontal à 200% de zoom',
                selector: 'body',
                context: `RGAA 10.4 - Largeur contenu: ${zoomIssues.scrollWidth}px, viewport: ${zoomIssues.clientWidth}px. Le contenu doit rester lisible sans défilement horizontal.`,
                rgaaCriteria: ['10.4'],
                wcagCriteria: ['1.4.10']
            });
        }

        // Reset viewport
        await page.setViewportSize(originalViewport);
        await page.waitForTimeout(300);

        // 10.10 - Horizontal scroll at normal size
        const scrollCheck = await page.evaluate(() => {
            const hasScroll = document.documentElement.scrollWidth > document.documentElement.clientWidth + 10;
            return {
                hasScroll,
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth
            };
        });

        if (scrollCheck.hasScroll) {
            test.issues.push({
                severity: 'major',
                message: 'Défilement horizontal en viewport standard',
                selector: 'body',
                context: `RGAA 10.10 - Largeur: ${scrollCheck.scrollWidth}px > ${scrollCheck.clientWidth}px`,
                rgaaCriteria: ['10.10'],
                wcagCriteria: ['1.4.10']
            });
        }

        // 10.11 - Text spacing (inject CSS and check for overflow)
        await page.addStyleTag({
            content: `
                .__a11y_spacing_test * {
                    line-height: 1.5 !important;
                    letter-spacing: 0.12em !important;
                    word-spacing: 0.16em !important;
                }
                .__a11y_spacing_test p {
                    margin-bottom: 2em !important;
                }
            `
        });

        // Add test class to body
        await page.evaluate(() => {
            document.body.classList.add('__a11y_spacing_test');
        });

        await page.waitForTimeout(300);

        const spacingIssues = await page.evaluate(() => {
            const elements = Array.from(document.querySelectorAll('p, h1, h2, h3, h4, h5, h6, li, td, th, span, a'));
            const overflowElements = [];

            elements.forEach(el => {
                // Skip empty or very small elements
                if (!el.textContent.trim() || el.clientWidth < 50) return;

                const hasOverflow = el.scrollWidth > el.clientWidth + 2 ||
                                   el.scrollHeight > el.clientHeight + 2;

                if (hasOverflow) {
                    overflowElements.push({
                        tag: el.tagName.toLowerCase(),
                        text: el.textContent.trim().substring(0, 50),
                        selector: el.tagName.toLowerCase() +
                                 (el.id ? '#' + el.id : '') +
                                 (el.className ? '.' + el.className.split(' ')[0] : ''),
                        scrollWidth: el.scrollWidth,
                        clientWidth: el.clientWidth
                    });
                }
            });

            return overflowElements.slice(0, 10); // Limit to first 10
        });

        if (spacingIssues.length > 0) {
            test.issues.push({
                severity: 'major',
                message: `${spacingIssues.length} élément(s) avec débordement après ajustement d'espacement`,
                selector: spacingIssues.map(e => e.selector).join(', '),
                context: `RGAA 10.11 - Le contenu doit rester lisible avec espacements augmentés (line-height: 1.5, letter-spacing: 0.12em). Exemples: ${spacingIssues.slice(0, 3).map(e => `${e.tag}: "${e.text}"`).join('; ')}`,
                rgaaCriteria: ['10.11'],
                wcagCriteria: ['1.4.12']
            });
        }

        // Remove test class
        await page.evaluate(() => {
            document.body.classList.remove('__a11y_spacing_test');
        });

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Enhanced navigation tests (RGAA 12.x)
 */
async function testNavigationEnhanced(page) {
    const test = {
        name: 'Enhanced Navigation (RGAA 12.x)',
        category: 'navigation',
        issues: []
    };

    try {
        // 12.3 - Sitemap link
        const sitemapCheck = await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('a'));
            const sitemapLink = links.find(link =>
                /plan\s+(?:du\s+)?site|sitemap|carte\s+du\s+site/i.test(link.textContent.trim())
            );

            return {
                found: !!sitemapLink,
                text: sitemapLink?.textContent.trim(),
                href: sitemapLink?.href
            };
        });

        if (!sitemapCheck.found) {
            test.issues.push({
                severity: 'minor',
                message: 'Aucun lien vers un plan du site détecté',
                selector: 'body',
                context: 'RGAA 12.3 - Un plan du site devrait être disponible pour faciliter la navigation',
                rgaaCriteria: ['12.3'],
                wcagCriteria: ['2.4.5']
            });
        }

        // 12.4 - Search functionality
        const searchCheck = await page.evaluate(() => {
            const searchInputs = document.querySelectorAll(
                'input[type="search"], ' +
                '[role="search"] input, ' +
                'input[name*="search" i], ' +
                'input[id*="search" i], ' +
                'input[placeholder*="recherch" i], ' +
                'input[aria-label*="recherch" i]'
            );

            return {
                found: searchInputs.length > 0,
                count: searchInputs.length
            };
        });

        if (!searchCheck.found) {
            test.issues.push({
                severity: 'minor',
                message: 'Aucun moteur de recherche interne détecté',
                selector: 'body',
                context: 'RGAA 12.4 - Un moteur de recherche interne est recommandé pour les sites avec plus de quelques pages',
                rgaaCriteria: ['12.4'],
                wcagCriteria: ['2.4.5']
            });
        }

        // 12.10 - Breadcrumb navigation
        const breadcrumbCheck = await page.evaluate(() => {
            const breadcrumb = document.querySelector(
                '[aria-label*="breadcrumb" i], ' +
                '[aria-label*="fil" i], ' +
                '.breadcrumb, ' +
                '[class*="fil-ariane" i], ' +
                '[class*="breadcrumb" i], ' +
                'nav ol, ' +
                'nav ul.breadcrumb'
            );

            return {
                found: !!breadcrumb,
                type: breadcrumb?.tagName,
                ariaLabel: breadcrumb?.getAttribute('aria-label')
            };
        });

        if (!breadcrumbCheck.found) {
            test.issues.push({
                severity: 'minor',
                message: 'Aucun fil d\'Ariane détecté',
                selector: 'body',
                context: 'RGAA 12.10 - Un fil d\'Ariane aide à comprendre la position dans l\'arborescence (recommandé pour sites > 2 niveaux)',
                rgaaCriteria: ['12.10'],
                wcagCriteria: ['2.4.8']
            });
        }

        test.status = test.issues.length === 0 ? 'passed' : 'warning';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Enhanced forms validation tests (RGAA 11.10, 11.11)
 */
async function testFormsEnhanced(page) {
    const test = {
        name: 'Enhanced Forms Validation (RGAA 11.x)',
        category: 'forms',
        issues: []
    };

    try {
        // 11.10 - Input validation patterns
        const validationCheck = await page.evaluate(() => {
            const inputs = document.querySelectorAll('input[type="email"], input[type="tel"], input[type="url"], input[pattern]');
            const results = [];

            inputs.forEach(input => {
                const hasPattern = input.hasAttribute('pattern') || ['email', 'tel', 'url'].includes(input.type);
                const hasErrorMessage = input.hasAttribute('aria-describedby') ||
                                       input.hasAttribute('aria-errormessage') ||
                                       input.closest('form')?.querySelector('[role="alert"]');
                const hasTitle = input.hasAttribute('title');

                if (hasPattern && !hasErrorMessage && !hasTitle) {
                    results.push({
                        type: input.type,
                        pattern: input.pattern,
                        id: input.id,
                        name: input.name,
                        selector: `input[name="${input.name}"]` || `input[type="${input.type}"]`
                    });
                }
            });

            return results;
        });

        if (validationCheck.length > 0) {
            test.issues.push({
                severity: 'major',
                message: `${validationCheck.length} champ(s) avec validation mais sans message d'aide`,
                selector: validationCheck.map(v => v.selector).join(', '),
                context: `RGAA 11.10 - Les champs avec contraintes doivent indiquer le format attendu. Exemples: ${validationCheck.slice(0, 3).map(v => v.type).join(', ')}`,
                rgaaCriteria: ['11.10'],
                wcagCriteria: ['3.3.2']
            });
        }

        // 11.11 - Error messages with suggestions
        const errorMessagesCheck = await page.evaluate(() => {
            const errorMessages = document.querySelectorAll(
                '[role="alert"], ' +
                '.error, ' +
                '.invalid, ' +
                '[class*="error" i], ' +
                '[aria-invalid="true"] ~ *, ' +
                'input:invalid ~ *'
            );

            const results = [];

            errorMessages.forEach(msg => {
                const text = msg.textContent.trim().toLowerCase();
                const hasSuggestion = /suggest|essayez|exemple|format|doit|devrait|veuillez|exemple|correct/i.test(text);

                if (!hasSuggestion && text.length > 10 && text.length < 200) {
                    results.push({
                        text: msg.textContent.trim().substring(0, 100),
                        selector: msg.className ? `.${msg.className.split(' ')[0]}` : msg.tagName.toLowerCase()
                    });
                }
            });

            return results.slice(0, 5);
        });

        if (errorMessagesCheck.length > 0) {
            test.issues.push({
                severity: 'major',
                message: `${errorMessagesCheck.length} message(s) d'erreur sans suggestion de correction`,
                selector: errorMessagesCheck.map(e => e.selector).join(', '),
                context: `RGAA 11.11 - Les messages d'erreur doivent suggérer une correction. Exemples: "${errorMessagesCheck[0]?.text}"`,
                rgaaCriteria: ['11.11'],
                wcagCriteria: ['3.3.3']
            });
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Script context changes tests (RGAA 7.4)
 */
async function testScriptContextChanges(page) {
    const test = {
        name: 'Script Context Changes (RGAA 7.4)',
        category: 'scripts',
        issues: []
    };

    try {
        // 7.4 - Detect uncontrolled context changes (select with onchange redirect)
        const contextChanges = await page.evaluate(() => {
            const results = [];

            // Check select elements with onchange that redirect
            const selects = document.querySelectorAll('select[onchange]');
            selects.forEach(select => {
                const onChange = select.getAttribute('onchange') || '';
                const hasRedirect = /window\.location|location\.href|location\.replace|submit|form\./i.test(onChange);

                if (hasRedirect) {
                    results.push({
                        type: 'select-redirect',
                        selector: select.id ? `select#${select.id}` : `select[name="${select.name}"]`,
                        onChange: onChange.substring(0, 100)
                    });
                }
            });

            // Check inputs with onblur/onchange that submit
            const inputs = document.querySelectorAll('input[onblur], input[onchange]');
            inputs.forEach(input => {
                const handler = input.getAttribute('onblur') || input.getAttribute('onchange') || '';
                const hasSubmit = /submit|form\./i.test(handler);

                if (hasSubmit) {
                    results.push({
                        type: 'input-autosubmit',
                        selector: input.id ? `input#${input.id}` : `input[name="${input.name}"]`,
                        handler: handler.substring(0, 100)
                    });
                }
            });

            return results;
        });

        if (contextChanges.length > 0) {
            test.issues.push({
                severity: 'critical',
                message: `${contextChanges.length} changement(s) de contexte non contrôlé(s) détecté(s)`,
                selector: contextChanges.map(c => c.selector).join(', '),
                context: `RGAA 7.4 - Les changements de contexte (redirect, submit) doivent être initiés par l'utilisateur via un bouton. Exemples: ${contextChanges.map(c => `${c.type} sur ${c.selector}`).join('; ')}`,
                rgaaCriteria: ['7.4'],
                wcagCriteria: ['3.2.2']
            });
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Update summary counts
 */
function updateSummary(summary, test) {
    if (test.status === 'passed') {
        summary.passed++;
    } else if (test.status === 'warning') {
        summary.warnings++;
    } else {
        summary.failed++;
    }
}

/**
 * Main execution
 */
async function main() {
    const url = process.argv[2];
    const auditScope = process.argv[3] || 'full'; // Default to 'full' if not provided

    if (!url) {
        console.error(JSON.stringify({ error: 'URL is required' }));
        process.exit(1);
    }

    // Validate audit scope
    const validScopes = ['full', 'transverse', 'main_content'];
    if (!validScopes.includes(auditScope)) {
        console.error(JSON.stringify({ error: `Invalid audit scope: ${auditScope}. Valid values: ${validScopes.join(', ')}` }));
        process.exit(1);
    }

    try {
        const results = await runPlaywrightAudit(url, auditScope);
        console.log(JSON.stringify(results, null, 2));
    } catch (error) {
        console.error(JSON.stringify({ error: error.message, stack: error.stack }));
        process.exit(1);
    }
}

main();
