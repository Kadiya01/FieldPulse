import { expect, test } from '@playwright/test';
import { launchApp, firstPage, signIn, captureAndSave, latestUuid } from './helpers';
import { readSubmissions, clearFaults } from './fixtures';

/**
 * E2E 1 — the offline capture lifecycle, across a real application restart.
 *
 * The claim under test is the one the whole product rests on: a field worker
 * with no signal can photograph a count, have it kept on the handset, and have
 * it reach the server when the signal returns — including if the app is closed
 * in between.
 *
 * Nothing here is simulated. The network is cut with the browser's own offline
 * switch, the photo is encoded from a live camera track, the restart is a
 * genuine context close and relaunch against the same profile directory, and
 * the final assertion reads the file back out of the real API.
 */
test.describe('offline capture lifecycle', () => {
  test.beforeEach(() => {
    clearFaults();
  });

  test('a photo taken offline survives a restart and uploads when the network returns', async (
    {},
    testInfo
  ) => {
    const context = await launchApp(testInfo, 'offline-lifecycle');
    const page = await firstPage(context);

    /*
     * Sign in while the network is up. Authentication needs the server, and
     * pretending otherwise would mean provisioning a session by hand — which
     * is the kind of shortcut that makes an offline test prove nothing.
     */
    await signIn(page);

    // --- Cut the network -------------------------------------------------
    await context.setOffline(true);

    // The app must report itself offline from its own reading, not a stub.
    await expect(page.getByText('Offline', { exact: true })).toBeVisible({ timeout: 15_000 });

    // --- Capture while offline -------------------------------------------
    await captureAndSave(page, 7);

    const uuid = await latestUuid(page);

    // The photo really is on the device: a real Blob, with real bytes.
    const offlineRows = await readSubmissions(page);
    const offlineRow = offlineRows.find((r) => r.submission_uuid === uuid);

    expect(offlineRow, 'the submission was stored locally').toBeDefined();
    expect(offlineRow!.status, 'queued for upload, not lost').toBe('PENDING');
    expect(offlineRow!.count_claimed).toBe(7);
    expect(offlineRow!.hasPhoto, 'the photo blob is retained').toBe(true);
    expect(offlineRow!.photoSize, 'the retained photo has real bytes').toBeGreaterThan(1000);
    expect(offlineRow!.server_submission_id, 'nothing has reached the server yet').toBeNull();

    // It must still be queued after the app has been closed and reopened.
    await context.close();

    // --- Restart the application -----------------------------------------
    // The same profile directory, deliberately *not* wiped: this launch exists
    // to prove the photo and the session were in durable storage rather than in
    // memory the harness happened to keep alive.
    const restarted = await launchApp(testInfo, 'offline-lifecycle', false);
    const page2 = await firstPage(restarted);

    // A persistent context opens on about:blank, so the reload has to be asked
    // for explicitly. Without this the page is still blank when the assertion
    // below runs, and the failure reads like a lost session rather than like a
    // missing navigation.
    page2.on('console', (m) => console.log(`[P2] ${m.type()}: ${m.text().slice(0, 200)}`));
    page2.on('requestfailed', (r) => console.log(`[P2FAIL] ${r.method()} ${r.url()} :: ${r.failure()?.errorText}`));
    page2.on('response', (r) => console.log(`[P2RESP] ${r.status()} ${r.request().method()} ${r.url()}`));
    await page2.goto('/');
    const swState = await page2.evaluate(() =>
      navigator.serviceWorker?.controller ? `${navigator.serviceWorker.controller.state}(${navigator.serviceWorker.controller.scriptURL})` : 'none'
    );
    console.log(`[P2] url=${page2.url()} swController=${swState}`);
    try {
      const body = await page2.locator('body').innerText({ timeout: 2500 });
      console.log(`[P2] body=${body.slice(0, 300).replace(/\n/g, ' ')}`);
    } catch {
      console.log('[P2] body=<unreadable>');
    }
    void swState;

    // Still signed in: the refresh cookie survived the restart too.
    await expect(page2.getByRole('heading', { name: 'Capture' })).toBeVisible({ timeout: 30_000 });

    const survived = await readSubmissions(page2);
    const survivedRow = survived.find((r) => r.submission_uuid === uuid);

    expect(survivedRow, 'the queue survived the restart').toBeDefined();
    expect(survivedRow!.hasPhoto, 'the photo survived the restart').toBe(true);
    expect(survivedRow!.photoSize).toBeGreaterThan(1000);

    // --- Restore the network and sync ------------------------------------
    await restarted.setOffline(false);

    await page2.goto('/queue');
    await page2.getByRole('button', { name: 'Sync now' }).click();

    // --- The upload completes --------------------------------------------
    const deadline = Date.now() + 60_000;
    let sent = false;

    for (;;) {
      const rows = await readSubmissions(page2);
      const row = rows.find((r) => r.submission_uuid === uuid);

      if (row?.status === 'SENT') {
        sent = true;
        // A local SENT means the server acknowledged receipt, and the client
        // prunes the photo only on that basis. If the photo is still here, the
        // client accepted something it should not have.
        expect(row.photo_blob === undefined || row.hasPhoto === false, 'the photo is pruned once the server has it').toBe(true);
        expect(row.hasPhoto, 'the photo is pruned once the server has it').toBe(false);
        expect(row.server_submission_id, 'the server assigned an id').toBeTruthy();
        break;
      }

      if (Date.now() > deadline) {
        throw new Error(`never reached SENT; last status: ${row?.status ?? 'no row'}`);
      }

      await page2.waitForTimeout(250);
    }

    expect(sent).toBe(true);

    /*
     * Finally, confirm against the real API that the server holds a record for
     * this exact identifier. A local SENT proves the client believed the server
     * answered; only this proves the server did.
     */
    const lookup = await page2.request.get(`/api/v1/submission.php?uuid=${uuid}`);

    expect(lookup.status(), 'the server knows this submission').toBe(200);

    const body = await lookup.json();

    expect(body.data.submission_uuid).toBe(uuid);
    expect(body.data.count_claimed).toBe(7);

    await restarted.close();
  });
});
