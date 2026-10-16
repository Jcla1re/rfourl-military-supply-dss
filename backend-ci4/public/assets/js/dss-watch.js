// DSS recalculation running in the background (weekly automatic run or
// "Recalculate now"). Loaded on every admin page with window.DSS_WATCH from
// the layout. While a run is in progress it polls admin/dss/status, and
// when the run finishes it shows the toast on whatever page the admin is on.
// The watch is kept in sessionStorage, so a run that finishes while the
// admin is moving between pages still gets its toast on the next page.
// Pages can listen for 'dss:finished' (e.g. Settings > DSS re-enables its
// button).
(function () {
    const cfg = window.DSS_WATCH;
    if (!cfg) return;
    const KEY = 'dssWatch';
    const read = () => { try { return JSON.parse(sessionStorage.getItem(KEY)); } catch (e) { return null; } };
    const write = v => { try { v ? sessionStorage.setItem(KEY, JSON.stringify(v)) : sessionStorage.removeItem(KEY); } catch (e) { /* storage blocked */ } };

    // baseline = the last finished run before this one; a different
    // finished_at later means this run has finished.
    let watch = read();
    if (cfg.running && !watch) {
        watch = { baseline: cfg.lastFinished || '' };
        write(watch);
    }
    if (!watch) return;

    const fmt = n => Number(n || 0).toLocaleString();
    const finish = s => {
        write(null);
        const last = s.last || {};
        window.dispatchEvent(new CustomEvent('dss:finished', { detail: s }));
        // The result replaces the "already running" toast in place (same key).
        if (!last.finished_at || last.finished_at === watch.baseline) return dismissToastByKey('dss'); // no new result to report
        const weekly = last.trigger === 'auto';
        if (last.ok) {
            showToast({
                type: 'success', key: 'dss',
                title: weekly ? 'Reorder points updated (weekly recalculation)' : `Recalculated ${fmt(last.items)} items`,
                message: (weekly ? `Recalculated ${fmt(last.items)} items · ` : '')
                    + `${fmt(last.with_rop)} now have a reorder point · Reorder alerts updated.`
                    + (last.note ? ' ' + last.note : ''),
            });
        } else {
            showToast({
                type: 'error', key: 'dss',
                title: weekly ? "Weekly recalculation didn't finish" : "Couldn't recalculate",
                message: (last.error ? last.error + ' ' : '') + 'Try Recalculate now in Settings > DSS.',
            });
        }
    };

    const startedAt = Date.now();
    const poll = () => fetch(cfg.statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(r => r.json())
        .then(s => {
            if (!s.running) return finish(s);
            if (Date.now() - startedAt < 15 * 60 * 1000) setTimeout(poll, 3000);
        })
        .catch(() => setTimeout(poll, 5000));

    // Still running: check again in a few seconds. Not running any more:
    // it finished between pages, so check right away.
    setTimeout(poll, cfg.running ? 3000 : 0);
})();
