/* Session Guard admin: save the handling status on change, confirm note deletion. */
(function () {
	'use strict';
	document.querySelectorAll('.ndsg-status-form').forEach(function (form) {
		var button = form.querySelector('.ndsg-status-save');
		if (button) button.style.display = 'none';
		form.querySelector('select').addEventListener('change', function () {
			form.submit();
		});
	});
	document.querySelectorAll('.ndsg-delete-note').forEach(function (link) {
		link.addEventListener('click', function (e) {
			if (!window.confirm((window.ndsgAdmin || {}).deleteNote || 'Delete?')) e.preventDefault();
		});
	});
})();
