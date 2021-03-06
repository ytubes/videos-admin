<?php
use yii\helpers\Url;
use yii\helpers\Html;
use ytubes\videos\models\VideoStatus;

$actionUrl = Url::to(['mass-actions/change-user']);

?>

<div class="modal-header">
	<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
	<h4 class="modal-title" id="modal-title">Изменение автора</h4>
</div>

<div class="modal-body">
	<div class="row">
		<div class="col-md-5 form-group field-selectuserform-per_page">
			<?= Html::dropDownList('user_id', [], $listUser, ['id' => 'select-user', 'class' => 'form-control'])?>
		</div>
	</div>

<div class="modal-footer" id="modal-footer">
	<button type="button" class="btn btn-default" data-dismiss="modal">Закрыть</button>
	<button type="button" class="btn btn-primary" id="change-status" data-url="<?= $actionUrl ?>">Изменить</button>
</div>

<script>
(function() {
	$('#change-status').click(function(event) {
		event.preventDefault();
		var actionUrl = $(this).data('url');
		var userId = $('#select-user').val();
		var keys = $('#list-videos').yiiGridView('getSelectedRows');

		if (keys.length == 0) {
			alert('Нужно выбрать хотябы 1 элемент');
			return;
		}

		$.post(actionUrl, { 'videos_ids[]':keys, 'user_id':userId }, function( data ) {
			if (data.status === 'success') {
				window.location.reload();
			} else if (data.status === 'error') {
				toastr.error('Ошибка какая-то');
			}
		}, 'json');
	});
})();
</script>