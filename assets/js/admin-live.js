/* global ndsgLive, wp */
(function () {
	'use strict';

	var cfg = window.ndsgLive;
	var body = document.getElementById('ndsg-live-body');
	var filter = document.getElementById('ndsg-filter');
	var multiOnly = document.getElementById('ndsg-multi-only');
	var updated = document.querySelector('.ndsg-updated');
	var t = cfg.i18n;
	var data = null;

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function ago(sec) {
		return sec < 90 ? t.secondsAgo.replace('%d', sec) : t.minutesAgo.replace('%d', Math.round(sec / 60));
	}

	function icon(type) {
		return type === 'mobile' ? 'dashicons-smartphone' : type === 'tablet' ? 'dashicons-tablet' : 'dashicons-desktop';
	}

	function load() {
		wp.apiFetch({ path: '/ndsg/v1/live' })
			.then(function (res) {
				data = res;
				render();
				updated.textContent = new Date().toLocaleTimeString();
			})
			.catch(function () {
				updated.textContent = t.error;
			});
	}

	function render() {
		if (!data) return;
		Object.keys(data.summary).forEach(function (k) {
			var el = document.querySelector('[data-stat="' + k + '"]');
			if (el) el.textContent = data.summary[k];
		});

		var q = filter.value.trim().toLowerCase();
		var perUser = {};
		data.sessions.forEach(function (s) {
			perUser[s.user_id] = (perUser[s.user_id] || 0) + 1;
		});

		var rows = data.sessions.filter(function (s) {
			if (multiOnly.checked && perUser[s.user_id] < 2) return false;
			if (!q) return true;
			return [s.name, s.email, s.ip, s.page, s.course, s.url].join(' ').toLowerCase().indexOf(q) !== -1;
		});

		if (!rows.length) {
			body.innerHTML = '<tr><td colspan="6" class="ndsg-empty">' + esc(t.empty) + '</td></tr>';
			return;
		}

		body.innerHTML = rows.map(function (s) {
			var multi = perUser[s.user_id] > 1;
			var viewing = s.course
				? '<strong>' + esc(s.course) + '</strong>' + (s.page && s.page !== s.course ? '<br><span class="description">' + esc(s.page) + '</span>' : '')
				: esc(s.page || s.url);
			return '<tr class="' + (multi ? 'ndsg-multi' : '') + '">' +
				'<td><a href="' + esc(cfg.userUrl + s.user_id) + '"><strong>' + esc(s.name) + '</strong></a><br><span class="description">' + esc(s.email) + '</span>' +
				(multi ? ' <span class="ndsg-pill">' + esc(t.devices.replace('%d', perUser[s.user_id])) + '</span>' : '') + '</td>' +
				'<td><span class="dashicons ' + icon(s.device_type) + '"></span> ' + esc(s.device) + '</td>' +
				'<td><code>' + esc(s.ip) + '</code>' + (s.country ? ' ' + esc(s.country) : '') + '</td>' +
				'<td class="ndsg-viewing" title="' + esc(s.url) + '">' + viewing + '</td>' +
				'<td>' + esc(ago(s.seconds_ago)) + '</td>' +
				'<td><button type="button" class="button button-small" data-kick="' + s.id + '">' + esc(t.kick) + '</button></td>' +
				'</tr>';
		}).join('');
	}

	body.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-kick]');
		if (!btn || !window.confirm(t.kickConfirm)) return;
		btn.disabled = true;
		wp.apiFetch({ path: '/ndsg/v1/sessions/' + btn.getAttribute('data-kick') + '/kick', method: 'POST' })
			.then(load)
			.catch(function () { btn.disabled = false; });
	});

	filter.addEventListener('input', render);
	multiOnly.addEventListener('change', render);

	load();
	setInterval(function () {
		if (document.visibilityState === 'visible') load();
	}, cfg.refresh * 1000);
})();
