(function () {
	'use strict';

	var counters = document.querySelectorAll('[data-salawat-stat]');

	if (!counters.length || typeof SalawatCounter === 'undefined') {
		return;
	}

	function refreshCounters() {
		var body = new window.FormData();
		body.append('action', SalawatCounter.action);

		window.fetch(SalawatCounter.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data || !payload.data.formatted) {
					return;
				}

				counters.forEach(function (counter) {
					var stat = counter.getAttribute('data-salawat-stat');
					if (payload.data.formatted[stat]) {
						counter.textContent = payload.data.formatted[stat];
					}
				});
			})
			.catch(function () {});
	}

	window.setInterval(refreshCounters, 10000);
}());
