/**
 * Netdesign Session Guard heartbeat.
 * Pings only while the tab is visible (or while an add-on reports a playing
 * video in it, cfg.video), and immediately when it regains focus, so an idle
 * or hidden tab costs nothing. On revocation it shows a notice and leaves the
 * page. Add-ons can call window.ndsgHeartbeat.ping() to send one right away.
 */
(function () {
	'use strict';

	var cfg = window.ndsgHeartbeat;
	if (!cfg || !window.fetch) return;

	var MIN_GAP = 5000;
	var busy = false;
	var stopped = false;
	var lastSent = 0;

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

		fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (d) { if (d && d.s === 'revoked') revoked(d.r); })
			.catch(function () {})
			.then(function () { busy = false; });
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
