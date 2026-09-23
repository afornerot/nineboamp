function ModalLoad(idmodal, title, path) {
	$("#" + idmodal + " .modal-header h4").text(title);
	$("#" + idmodal + " #framemodal").attr("src", path);
}

$(document).ready(function () {
	$(document).on('select2:open', () => {
		setTimeout(() => {
			let input = document.querySelector('.select2-container--open .select2-search__field');
			if (input) input.focus();
		}, 0);
	});
});

$(document).ready(function () {
	$('.select2').select2({
		theme: 'bootstrap-5',
		templateResult: function (data) {
			if (!data.id) return data.text;

			const $result = $('<span>');
			const iconClass = $(data.element).data('icon');
			if (iconClass) {
				$result.append($('<i>').addClass(iconClass + ' me-2'));
			}
			$result.append($('<span>').text(data.text));

			return $result;
		},
		templateSelection: function (data) {
			if (!data.id) return data.text;

			const $selection = $('<span>');
			const iconClass = $(data.element).data('icon');
			if (iconClass) {
				$selection.append($('<i>').addClass(iconClass + ' me-2'));
			}
			$selection.append($('<span>').text(data.text));

			return $selection;
		}
	});
});

$(function () {
	$('[data-bs-toggle="tooltip"]').tooltip();
});



// DataTables : init automatique pour toute table #dataTables.
// Options par table via data-attributes : data-order-col (index), data-order-dir.
$(document).ready(function () {
	const $table = $('#dataTables');

	if (!$table.length || typeof $.fn.DataTable === 'undefined') return;

	if ($table.find('tbody tr:not(.empty-row)').length === 0) return;

	$orderCol = $table.data('order-col') ?? 0;
	$orderDir = $table.data('order-dir') ?? 'asc';

	$table.DataTable({
		columnDefs: [
			{ targets: 'no-sort', orderable: false },
			{ targets: 'no-string', type: 'num' },
		],
		responsive: true,
		iDisplayLength: 100,
		order: [[$orderCol, $orderDir]],
	});
});
