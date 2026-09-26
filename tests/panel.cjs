'use strict';

const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

// Only the synthetic fixture from prepare-panel.php is supported.
const baseURL = 'http://127.0.0.1:8088';
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret].map(char => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = crypto.createHmac('sha1', key).update(counter).digest();
    return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}
const future = days => new Date(Date.now() + days * 86400000).toISOString().slice(0, 16);

(async () => {
    const browser = await chromium.launch({
        executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: true,
    });
    const errors = [];
    const screenshotDir = path.join(__dirname, '../var/screenshots');
    fs.mkdirSync(screenshotDir, { recursive: true });
    const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    page.on('response', response => {
        const route = new URL(response.url()).pathname;
        if (process.env.PANEL_DEBUG && ['/login', '/mfa'].includes(route)) {
            console.log(response.request().method(), route, response.status(), response.headers().location || '', 'cookie:', Boolean(response.headers()['set-cookie']), 'origin:', response.request().headers().origin);
        }
    });
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error' && !message.text().includes('favicon')) errors.push(message.text()); });
    async function submit(button) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), button.click()]);
    }
    async function screenshot(name) {
        await page.screenshot({ path: path.join(screenshotDir, name + '.png'), fullPage: true });
    }
    try {
        await page.goto('/admin/licenses');
        assert.equal(new URL(page.url()).pathname, '/login');
        await screenshot('login-desktop');
        await page.locator('#login').fill('admin@example.test');
        await page.locator('#password').fill('Fixture-Password-Only-2026!');
        await submit(page.getByRole('button', { name: 'Continuar' }));
        assert.equal(new URL(page.url()).pathname, '/mfa', await page.locator('[role="alert"]').allTextContents().then(values => values.join('; ')));
        const secret = await page.locator('[data-testid="totp-secret"]').textContent();
        assert.match(await page.locator('.qr-wrap img').getAttribute('src'), /^data:image\/svg\+xml;base64,/);
        await page.locator('#code').fill(totp(secret));
        await submit(page.getByRole('button', { name: 'Verificar e ingresar' }));
        assert.equal(await page.locator('.recovery-grid code').count(), 8);
        await page.getByRole('link', { name: 'Ya los guardé · Ir al panel' }).click();
        await page.waitForURL('**/admin');
        await screenshot('dashboard-desktop');
        assert.equal((await context.cookies()).find(cookie => cookie.name === 'aibid_admin_dev').httpOnly, true);

        await page.goto('/admin/licenses');
        await page.locator('tbody tr').filter({ hasText: 'Centro Documental del Norte' }).getByRole('link', { name: 'Detalle' }).click();
        await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
        const signedDownload = await page.getByRole('link', { name: 'Descargar .lic' }).getAttribute('href');
        const signedFile = await context.request.get(signedDownload);
        assert.equal(signedFile.status(), 200);
        assert.match(signedFile.headers()['content-disposition'], /^attachment; filename="license-/);
        const compact = await signedFile.text();
        assert.match(compact, /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{86}$/);
        const signedPayload = JSON.parse(Buffer.from(compact.split('.')[1], 'base64url').toString('utf8'));
        assert.equal(signedPayload.license_id, new URL(page.url()).pathname.split('/').pop());
        assert.equal(signedPayload.license_revision, 1);
        await screenshot('activated-license-desktop');

        await page.goto('/admin/customers/new');
        await page.locator('#name').fill('Biblioteca de Pruebas');
        await page.locator('#legal_name').fill('<script>window.unwantedScript=true</script>');
        await page.locator('#contact_name').fill('Contacto de pruebas');
        await page.locator('#email').fill('biblioteca@example.test');
        await submit(page.getByRole('button', { name: 'Guardar cliente' }));
        const customerId = new URL(page.url()).pathname.split('/').pop();
        assert.match(customerId, /^[a-f0-9-]{36}$/);
        assert.equal(await page.evaluate(() => window.unwantedScript), undefined);
        assert.equal(await page.locator('#legal_name').inputValue(), '<script>window.unwantedScript=true</script>');

        await page.goto('/admin/licenses/new');
        await page.locator('#customer_id').selectOption(customerId);
        await page.locator('[value="review_workflow"]').check();
        assert.equal(await page.locator('[value="expedientes"]').isChecked(), true);
        assert.equal(await page.locator('[value="managed_libraries"]').isChecked(), false);
        await page.locator('#reference').fill('BROWSER-001');
        await page.locator('#reason').fill('Emisión de prueba en navegador');
        await screenshot('license-form-desktop');
        await submit(page.getByRole('button', { name: 'Emitir y mostrar clave' }));
        const commercialKey = await page.locator('#commercial-key').inputValue();
        assert.match(commercialKey, /^AIBID-[A-Za-z0-9_-]{43}$/);
        await page.getByRole('link', { name: 'Ya la guardé · Ver licencia' }).click();
        await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
        const licenseURL = page.url();
        assert.match(await page.locator('main').innerText(), /Sin vencimiento/);
        assert.match(await page.locator('main').innerText(), /No contratado/);
        assert.equal((await page.content()).includes(commercialKey), false);
        await page.locator('#until').fill(future(365));
        await page.locator('#reference').fill('BROWSER-MAINT');
        await page.locator('#term_reason').fill('Compra separada de mantenimiento');
        await submit(page.getByRole('button', { name: 'Registrar compra' }));
        assert.equal(page.url(), licenseURL);
        assert.match(await page.locator('main').innerText(), /Sin vencimiento/);
        assert.equal((await page.locator('main').innerText()).includes('No contratado'), false);
        await screenshot('license-detail-desktop');

        const csrf = await page.locator('[name="csrf"]').first().inputValue();
        let response = await context.request.post('/admin/customers/create', { form: { csrf: 'invalid', operation_id: crypto.randomUUID() }, maxRedirects: 0 });
        assert.equal(response.status(), 403);
        response = await context.request.post('/admin/customers/create', { headers: { Origin: 'https://untrusted.example' }, form: { csrf, operation_id: crypto.randomUUID() }, maxRedirects: 0 });
        assert.equal(response.status(), 403);
        response = await context.request.get('/v1/activations/challenge');
        assert.equal(response.status(), 405);
        assert.match((await response.json()).error.request_id, /^[a-f0-9-]{36}$/);

        await page.goto('/admin/licenses/new');
        await page.locator('#customer_id').selectOption(customerId);
        await page.locator('[value="subscription"]').check();
        assert.equal(await page.locator('#entitled_release_until').isDisabled(), true);
        await page.locator('#expires_at').fill(future(30));
        await page.locator('#reason').fill('Suscripción de prueba');
        await submit(page.getByRole('button', { name: 'Emitir y mostrar clave' }));
        await page.getByRole('link', { name: 'Ya la guardé · Ver licencia' }).click();
        await page.waitForURL(/\/admin\/licenses\/[a-f0-9-]{36}$/);
        assert.match(await page.locator('main').innerText(), /15 días/);
        await page.locator('#until').fill(future(60));
        await page.locator('#term_reason').fill('Renovación de prueba');
        await submit(page.getByRole('button', { name: 'Confirmar renovación' }));
        assert.equal(await page.locator('tbody tr').count(), 2);

        for (const route of ['/admin/customers', '/admin/products', '/admin/products/gestor_documental', '/admin/licenses', '/admin/audit', '/admin/users', '/admin/security']) {
            const result = await page.goto(route);
            assert.equal(result.status(), 200, route);
        }
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/admin');
        await screenshot('dashboard-mobile');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true,
            await page.evaluate(() => [...document.querySelectorAll('body *')].filter(element => element.getBoundingClientRect().right > window.innerWidth + 1 && getComputedStyle(element).position !== 'fixed').map(element => element.tagName + '.' + element.className).slice(0, 20).join(', ')));
        await page.getByRole('button', { name: 'Abrir navegación' }).click();
        assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'), 'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'), 'false');

        const viewer = await browser.newContext({ baseURL });
        const readPage = await viewer.newPage();
        await readPage.goto('/login');
        await readPage.locator('#login').fill('viewer@example.test');
        await readPage.locator('#password').fill('Fixture-Viewer-Only-2026!');
        await Promise.all([readPage.waitForURL('**/mfa'), readPage.getByRole('button', { name: 'Continuar' }).click()]);
        await readPage.locator('#code').fill(totp('JBSWY3DPEHPK3PXP'));
        await Promise.all([readPage.waitForURL('**/admin'), readPage.getByRole('button', { name: 'Verificar e ingresar' }).click()]);
        await readPage.goto('/admin/licenses');
        assert.equal(await readPage.getByRole('link', { name: 'Emitir licencia', exact: true }).count(), 0);
        const viewerCsrf = await readPage.locator('[name="csrf"]').first().inputValue();
        response = await viewer.request.post('/admin/customers/create', { form: { csrf: viewerCsrf, operation_id: crypto.randomUUID(), name: 'Unauthorized', contact_name: 'Attempt', email: '', state: 'active' }, maxRedirects: 0 });
        assert.equal(response.status(), 403);
        response = await viewer.request.get('/admin/users');
        assert.equal(response.status(), 403);
        response = await viewer.request.get(signedDownload);
        assert.equal(response.status(), 403);
        await viewer.close();
        assert.deepEqual(errors, [], 'No JavaScript or console errors');
        console.log('Panel OK: login, MFA, roles, CSRF/origin, escaped inputs, issuance, maintenance, renewal, signed history/download and responsive navigation.');
        console.log('Screenshots: var/screenshots (synthetic data, no credentials).');
    } catch (error) {
        if (process.env.PANEL_DEBUG && new URL(page.url()).pathname === '/login') console.error(await page.locator('main').innerText());
        console.error(error);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
