(function () {
	'use strict';

	var canvas = document.getElementById('salawat-admin-chart');

	if (!canvas) {
		return;
	}

	var data = [];

	try {
		data = JSON.parse(canvas.getAttribute('data-chart') || '[]');
	} catch (error) {
		data = [];
	}

	var context = canvas.getContext('2d');
	var width = canvas.width = canvas.offsetWidth || 900;
	var height = canvas.height = 260;
	var padding = 34;
	var values = data.map(function (row) {
		return parseInt(row.total_amount, 10) || 0;
	});
	var max = Math.max.apply(null, values.concat([1]));
	var barWidth = values.length ? Math.max(4, (width - padding * 2) / values.length - 4) : 0;

	context.clearRect(0, 0, width, height);
	context.fillStyle = '#fff';
	context.fillRect(0, 0, width, height);
	context.strokeStyle = '#dcdcde';
	context.beginPath();
	context.moveTo(padding, padding);
	context.lineTo(padding, height - padding);
	context.lineTo(width - padding, height - padding);
	context.stroke();

	if (!values.length) {
		context.fillStyle = '#646970';
		context.font = '14px sans-serif';
		context.fillText('No pledge data for this range.', padding + 12, height / 2);
		return;
	}

	values.forEach(function (value, index) {
		var x = padding + index * ((width - padding * 2) / values.length) + 2;
		var barHeight = Math.max(2, (value / max) * (height - padding * 2));
		var y = height - padding - barHeight;

		context.fillStyle = '#2271b1';
		context.fillRect(x, y, barWidth, barHeight);
	});

	context.fillStyle = '#50575e';
	context.font = '12px sans-serif';
	context.fillText('0', 8, height - padding);
	context.fillText(String(max), 8, padding + 4);
}());
