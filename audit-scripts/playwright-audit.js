#!/usr/bin/env node

const { chromium } = require('playwright');

/**
 * Playwright Accessibility Audit Script
 * Tests interactivity: keyboard navigation, focus management, dynamic content
 */

async function runPlaywrightAudit(url) {
    const browser = await chromium.launch({
        headless: true
    });

    const context = await browser.newContext({
        viewport: { width: 1920, height: 1080 }
    });

    const page = await context.newPage();
    const results = {
        url: url,
        timestamp: new Date().toISOString(),
        tests: [],
        summary: {
            passed: 0,
            failed: 0,
            warnings: 0
        }
    };

    try {
        // Use domcontentloaded instead of networkidle for better reliability
        // Many modern sites never reach networkidle due to analytics, ads, etc.
        await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: 120000  // 2 minutes for slow sites
        });

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

        // Test 8: HTML_CodeSniffer Analysis (complements axe-core)
        const htmlcsTest = await testHTMLCodeSniffer(page);
        results.tests.push(htmlcsTest);
        updateSummary(results.summary, htmlcsTest);

    } catch (error) {
        results.error = error.message;
    } finally {
        await browser.close();
    }

    return results;
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
        // Check for inputs without labels
        const unlabeledInputs = await page.evaluate(() => {
            const inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
            return Array.from(inputs)
                .filter(input => {
                    const id = input.id;
                    const ariaLabel = input.getAttribute('aria-label');
                    const ariaLabelledBy = input.getAttribute('aria-labelledby');
                    const hasLabel = id && document.querySelector(`label[for="${id}"]`);
                    return !hasLabel && !ariaLabel && !ariaLabelledBy;
                })
                .map(input => ({
                    tagName: input.tagName,
                    type: input.type,
                    name: input.name,
                    selector: input.tagName + (input.id ? '#' + input.id : '') + (input.name ? '[name="' + input.name + '"]' : '')
                }));
        });

        unlabeledInputs.forEach(input => {
            test.issues.push({
                severity: 'critical',
                message: 'Champ de formulaire sans étiquette',
                selector: input.selector,
                context: `${input.tagName} (${input.type}) sans label associé`
            });
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
 * Test avec HTML_CodeSniffer (complément d'axe-core)
 * Vérifie la conformité HTML et WCAG supplémentaire
 */
async function testHTMLCodeSniffer(page) {
    const test = {
        name: 'HTML_CodeSniffer Analysis (HTML/WCAG)',
        category: 'static-analysis',
        issues: []
    };

    try {
        // Injecter HTML_CodeSniffer dans la page
        await page.addScriptTag({
            path: require.resolve('html_codesniffer/build/HTMLCS.js')
        });

        // Exécuter HTML_CodeSniffer avec WCAG2AA
        const results = await page.evaluate(() => {
            return new Promise((resolve) => {
                // Helper function dans le contexte navigateur
                function getElementSelector(element) {
                    if (!element) return 'unknown';
                    if (!element.tagName) return 'unknown';
                    if (element.id) return `#${element.id}`;
                    if (element.className && typeof element.className === 'string') {
                        const firstClass = element.className.split(' ')[0];
                        if (firstClass) {
                            return `${element.tagName.toLowerCase()}.${firstClass}`;
                        }
                    }
                    return element.tagName.toLowerCase();
                }

                // Exécuter HTMLCS
                window.HTMLCS.process('WCAG2AA', document, function() {
                    const messages = window.HTMLCS.getMessages();

                    // Mapper les messages avec sélecteurs
                    const mapped = messages.map(msg => ({
                        type: msg.type,
                        code: msg.code,
                        message: msg.msg,
                        selector: msg.element ? getElementSelector(msg.element) : 'unknown',
                        context: (msg.element && msg.element.outerHTML) ? msg.element.outerHTML.substring(0, 200) : ''
                    }));

                    resolve(mapped);
                });
            });
        });

        // Mapper les messages en issues
        if (results && results.length > 0) {
            for (const message of results) {
                // Ignorer les notices (info only)
                if (message.type === 3) continue; // 3 = Notice

                test.issues.push({
                    severity: mapHTMLCSSeverity(message.type),
                    message: `${message.code}: ${message.message}`,
                    selector: message.selector,
                    context: message.context,
                    wcagCriteria: extractWCAGCriteria(message.code)
                });
            }
        }

        test.status = test.issues.length === 0 ? 'passed' : 'failed';
        test.totalMessages = results ? results.length : 0;

    } catch (error) {
        test.status = 'error';
        test.error = error.message;
    }

    return test;
}

/**
 * Map HTML_CodeSniffer severity to our severity levels
 */
function mapHTMLCSSeverity(type) {
    switch(type) {
        case 1: // Error
            return 'critical';
        case 2: // Warning
            return 'major';
        case 3: // Notice
            return 'minor';
        default:
            return 'minor';
    }
}

/**
 * Extract WCAG criteria from HTMLCS code
 */
function extractWCAGCriteria(code) {
    const match = code.match(/WCAG2AA\.Principle(\d)\.Guideline(\d)_(\d)\.(\d)_(\d)_(\d)/);
    if (match) {
        return [`wcag${match[1]}${match[2]}${match[3]}`];
    }
    return [];
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

    if (!url) {
        console.error(JSON.stringify({ error: 'URL is required' }));
        process.exit(1);
    }

    try {
        const results = await runPlaywrightAudit(url);
        console.log(JSON.stringify(results, null, 2));
    } catch (error) {
        console.error(JSON.stringify({ error: error.message, stack: error.stack }));
        process.exit(1);
    }
}

main();
