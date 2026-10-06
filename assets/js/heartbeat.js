/**
 * Netdesign Session Guard heartbeat.
 * Pings only while the tab is visible (or while an add-on reports a playing
 * video in it, cfg.video), and immediately when it regains focus, so an idle
 * or hidden tab costs nothing. On revocation it shows a notice and leaves the
 * page. Add-ons can call window.ndsgHeartbeat.ping() to send one right away.
 * If the endpoint fails (blocked, missing, not JSON), it switches to the REST
 * fallback (cfg.fallback) and remembers that for this site.
 */
(function () {
	'use strict';

	var cfg = window.ndsgHeartbeat;
	if (!cfg || !window.fetch) return;

	var MIN_GAP = 5000;
	var busy = false;
	var stopped = false;
	var lastSent = 0;
	// Identifies this page view, so add-ons can tell open tabs of one session apart.
	var view = Math.random().toString(36).slice(2, 12);

	// An https page can't call an http address (mixed content).
	if (location.protocol === 'https:') {
		cfg.url = cfg.url.replace(/^http:/, 'https:');
		if (cfg.fallback) cfg.fallback = cfg.fallback.replace(/^http:/, 'https:');
	}
	// The fast endpoint was blocked earlier on this site (e.g. a host that
	// forbids PHP files in plugins): go straight to the REST fallback.
	try {
		if (cfg.fallback && window.localStorage.getItem('ndsg_hb_fallback') === cfg.url) cfg.url = cfg.fallback;
	} catch (e) { /* storage unavailable */ }

	function useFallback() {
		if (!cfg.fallback || cfg.url === cfg.fallback) return false;
		try { window.localStorage.setItem('ndsg_hb_fallback', cfg.url); } catch (e) { /* ignore */ }
		cfg.url = cfg.fallback;
		return true;
	}

	function send() {
		if (stopped || busy || (document.visibilityState !== 'visible' && !cfg.video)) return;
		var now = Date.now();
		if (now - lastSent < MIN_GAP) return;
		lastSent = now;
		busy = true;

		var body = new URLSearchParams();
		body.set('k', cfg.key);
		body.set('u', location.pathname + location.search);
		body.set('p', cfg.post || 0);
		body.set('c', cfg.course || 0);
		body.set('i', cfg.interval || 30);
		body.set('v', cfg.video || '');
		if (cfg.video && cfg.videoTitle) body.set('vt', String(cfg.videoTitle).slice(0, 150));
		body.set('w', view);
		if (cfg.monitor === 0) body.set('m', '0');

		var retry = false;
		fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) {
				if (!r.ok) throw new Error('status ' + r.status);
				return r.json();
			})
			.then(function (d) {
				if (!d || typeof d.s !== 'string') throw new Error('bad response');
				if (d.s === 'revoked') revoked(d.r);
			})
			.catch(function () {
				// Blocked, missing or not JSON: switch to the REST endpoint and try again now.
				retry = useFallback();
			})
			.then(function () {
				busy = false;
				if (retry) {
					lastSent = 0;
					send();
				}
			});
	}

	function revoked(reason) {
		stopped = true;
		// Signed out on purpose (logout in another tab, expiry): just refresh.
		if (reason !== 'policy' && reason !== 'admin') {
			location.reload();
			return;
		}
		showNotice();
	}

	function showNotice() {
		var overlay = document.createElement('div');
		overlay.setAttribute('role', 'alertdialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:16px';

		var box = document.createElement('div');
		box.style.cssText = 'background:#fff;color:#1d2327;max-width:440px;width:100%;border-radius:10px;padding:24px;font:16px/1.5 system-ui,sans-serif;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.3)';

		var text = document.createElement('p');
		text.style.margin = '0 0 18px';
		text.textContent = cfg.message;

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.textContent = cfg.button;
		btn.style.cssText = 'background:#2271b1;color:#fff;border:0;border-radius:6px;padding:10px 28px;font:inherit;cursor:pointer';
		btn.addEventListener('click', go);

		box.appendChild(text);
		box.appendChild(btn);
		overlay.appendChild(box);
		document.body.appendChild(overlay);
		btn.focus();

		// Leave the page even if nobody clicks.
		setTimeout(go, 15000);
	}

	function go() {
		location.href = cfg.redirect;
	}

	cfg.ping = function () {
		lastSent = 0;
		send();
	};

	setTimeout(send, 2000);
	setInterval(send, Math.max(10, cfg.interval) * 1000);
	document.addEventListener('visibilitychange', send);
	window.addEventListener('focus', send);
})();
