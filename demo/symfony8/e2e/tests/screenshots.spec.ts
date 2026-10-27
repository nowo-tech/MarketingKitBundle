import { test, expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { ADMIN, prepareDemoState } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots in full demo context: demo masthead + Marketing Kit admin (UiKit scaffolds) +
 * Symfony web debug toolbar, on a realistic state (catalog seeded, YAML imported, GA4 and Meta Pixel configured).
 * Captured as PNG; `make demo-screenshots` converts them to WebP and deletes the PNGs.
 */
test.describe.configure({ mode: 'serial' });

const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1440;
const HEIGHT = 900;

/**
 * Captures the viewport (masthead → bundle UI → fixed web debug toolbar) once the toolbar is rendered. When `until`
 * is given, the viewport grows so that element is fully visible above the toolbar.
 */
async function captureDemoFrame(page: Page, file: string, until?: Locator): Promise<void> {
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible();
  if (until) {
    const bottom = await until.evaluate((el) => el.getBoundingClientRect().bottom + window.scrollY);
    const toolbarHeight = (await toolbar.boundingBox())?.height ?? 40;
    await page.setViewportSize({ width: WIDTH, height: Math.max(HEIGHT, Math.ceil(bottom + 32 + toolbarHeight)) });
    await expect(toolbar).toBeVisible();
  }
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => setTimeout(done, 400))));
  await page.screenshot({ path: resolve(outDir, file) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Marketing Kit screenshots (full demo frame)', () => {
  test.use({ viewport: { width: WIDTH, height: HEIGHT }, colorScheme: 'light' });

  test('admin list — providers with IDs, on/off state and row actions', async ({ page }) => {
    await prepareDemoState(page);
    await page.goto(ADMIN);
    const table = page.getByRole('table');
    await expect(page.getByTestId('marketing-kit-state-ga4')).toHaveText('On');
    await captureDemoFrame(page, 'admin-list.png', table);
  });

  test('edit form — provider options with ID validation', async ({ page }) => {
    await page.goto(ADMIN);
    await page.getByTestId('marketing-kit-edit-ga4').click();
    await expect(page.getByRole('heading', { name: 'Edit service', level: 1 })).toBeVisible();
    await page.locator('#marketing_tool_option_measurement_id').fill('G-123;alert(1)');
    await page.getByRole('button', { name: 'Save' }).click();
    const error = page.getByText('This ID does not match the provider format.');
    await expect(error).toBeVisible();
    await captureDemoFrame(page, 'admin-form.png', page.getByRole('button', { name: 'Save' }));
  });
});
