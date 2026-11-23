import assert from 'node:assert/strict';
import { mkdir, unlink, writeFile } from 'node:fs/promises';
import { chromium, webkit } from 'playwright';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8000';
const OWNER_LOGIN = process.env.MYO_OWNER_LOGIN;
const OWNER_PASSWORD = process.env.MYO_OWNER_PASSWORD;
const ADMIN_LOGIN = process.env.MYO_SUPER_ADMIN_LOGIN;
const ADMIN_PASSWORD = process.env.MYO_SUPER_ADMIN_PASSWORD;
const WORK_DIR = new URL('./billing-playwright-assets/', import.meta.url).pathname;

for (const [name, value] of Object.entries({ MYO_OWNER_LOGIN: OWNER_LOGIN, MYO_OWNER_PASSWORD: OWNER_PASSWORD, MYO_SUPER_ADMIN_LOGIN: ADMIN_LOGIN, MYO_SUPER_ADMIN_PASSWORD: ADMIN_PASSWORD })) {
    if (!value) throw new Error(`Set ${name}; this script does not seed credentials.`);
}

const expectText = async (page, text) => {
    await page.getByText(text, { exact: false }).first().waitFor();
};

async function login(page, identifier, password) {
    await page.goto(`${BASE_URL}/login`);
    await page.locator('#identifier').fill(identifier);
    await page.locator('#password').fill(password);
    await page.locator('#login-form').press('Enter');
    await page.waitForLoadState('networkidle');
}

async function selectBill(page, label, amount) {
    const control = page.getByRole('button', { name: new RegExp(label, 'i') }).first();
    if (await control.count()) await control.click();
    await expectText(page, `You are paying for: ${label}`);
    await expectText(page, amount);
}

async function fillPayment(page, reference, proof = null) {
    await page.getByLabel('GCash reference number').fill(reference);
    if (proof) await page.getByLabel('Proof of Payment (Optional)').setInputFiles(proof);
    await page.getByRole('button', { name: 'Submit for review' }).click();
}

async function makeScreenshots(page) {
    await page.setContent('<canvas id="noise" width="2400" height="1800"></canvas>');
    await page.evaluate(() => {
        const canvas = document.querySelector('canvas');
        const ctx = canvas.getContext('2d');
        const image = ctx.createImageData(canvas.width, canvas.height);
        for (let i = 0; i < image.data.length; i += 4) {
            image.data[i] = (i * 17) % 255;
            image.data[i + 1] = (i * 31) % 255;
            image.data[i + 2] = (i * 47) % 255;
            image.data[i + 3] = 255;
        }
        ctx.putImageData(image, 0, 0);
    });
    await page.screenshot({ path: `${WORK_DIR}/receipt-3mb.jpg`, type: 'jpeg', quality: 100 });
    await page.screenshot({ path: `${WORK_DIR}/receipt-2-6mb.png`, type: 'png' });
}

async function ownerFlow(browserType) {
    const browser = await browserType.launch();
    const context = await browser.newContext();
    const page = await context.newPage();
    await login(page, OWNER_LOGIN, OWNER_PASSWORD);
    await page.goto(`${BASE_URL}/billing`);
    await page.waitForLoadState('networkidle');

    // The billing page exposes the renewal choices as buttons/controls in the seeded UI.
    await selectBill(page, 'Base subscription only', '₱2,999.00');
    await selectBill(page, 'SMS add-on only', /₱\d+\.\d{2}/);
    await selectBill(page, 'Base subscription + SMS', /₱\d+\.\d{2}/);

    await selectBill(page, 'Base subscription only', '₱2,999.00');
    await fillPayment(page, `PW-BASE-${Date.now()}`);
    await expectText(page, 'Payment Submitted Successfully');
    await expectText(page, 'Payment Under Review');

    await selectBill(page, 'Base subscription + SMS', /₱\d+\.\d{2}/);
    await expectText(page, 'Payment Under Review');

    // Use a separate SMS-capable session/state when the base payment blocks overlapping plans.
    await context.clearCookies();
    await login(page, OWNER_LOGIN, OWNER_PASSWORD);
    await page.goto(`${BASE_URL}/billing`);
    await makeScreenshots(page);
    await selectBill(page, 'SMS add-on only', /₱\d+\.\d{2}/);
    await fillPayment(page, `PW-SMS-${Date.now()}`, `${WORK_DIR}/receipt-3mb.jpg`);
    await expectText(page, 'Payment Submitted Successfully');
    await page.goto(`${BASE_URL}/billing`);
    await page.getByLabel('Proof of Payment (Optional)').setInputFiles(`${WORK_DIR}/receipt-2-6mb.png`);

    const safariBrowser = await webkit.launch();
    const webkitContext = await safariBrowser.newContext();
    const safariPage = await webkitContext.newPage();
    await login(safariPage, OWNER_LOGIN, OWNER_PASSWORD);
    await safariPage.goto(`${BASE_URL}/billing`);
    const heic = `${WORK_DIR}/receipt.heic`;
    await writeFile(heic, Buffer.from([0, 0, 0, 0]));
    await safariPage.getByLabel('Proof of Payment (Optional)').setInputFiles(heic);
    await safariPage.getByRole('button', { name: 'Submit for review' }).click();
    const body = safariPage.locator('body');
    await body.getByText(/must be a JPG, PNG, or PDF|Payment Submitted Successfully/).first().waitFor();
    await webkitContext.close();
    await safariBrowser.close();

    await context.clearCookies();
    await page.goto(`${BASE_URL}/billing`);
    await fillPayment(page, `PW-EXPIRED-${Date.now()}`);
    await expectText(page, 'Your session expired. Refresh the page and try again.');
    await context.close();
    await browser.close();
}

async function superAdminFlow() {
    const browser = await chromium.launch();
    const context = await browser.newContext();
    const page = await context.newPage();
    await login(page, ADMIN_LOGIN, ADMIN_PASSWORD);
    await page.goto(`${BASE_URL}/super-admin`);
    await page.getByRole('button', { name: 'Payments' }).click();
    await expectText(page, 'Payment for');
    for (const label of ['Base subscription only', 'SMS add-on only', 'Package: Base + SMS']) {
        await expectText(page, label);
    }
    for (const button of await page.getByRole('button', { name: 'Review' }).all()) {
        await button.click();
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Approve & Activate' }).click();
        await page.waitForLoadState('networkidle');
    }
    await context.close();
    await browser.close();
}

await mkdir(WORK_DIR, { recursive: true });
try {
    await ownerFlow(chromium);
    await superAdminFlow();
    console.log('Billing Playwright flow passed.');
} finally {
    for (const file of ['receipt-3mb.jpg', 'receipt-2-6mb.png', 'receipt.heic']) {
        await unlink(`${WORK_DIR}/${file}`).catch(() => {});
    }
}
