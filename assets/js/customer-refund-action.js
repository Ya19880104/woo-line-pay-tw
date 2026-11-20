/**
 * 
 */
(function($) {

	$(document).ready(function() {
		
		function restoreClickBinder(el) {
			var lang_pack = linepayRefundText;
			
			el.bind('click', requestCancelByCustomer);
			el.text(lang_pack.cancel);
		}
		
		function requestCancelByCustomer(evt) {
			evt.stopPropagation();
			evt.preventDefault();
			
			var lang_pack = linepayRefundText;
			var el = $(evt.target);
			if (!el || el.prop('tagName') !== 'A') {
				console.error('The Element is not an \'A\' tag for cancel');
				return;
			}
			
			var orderId = el.closest('tr').find('.order-number > a').text().trim();
			if ( ! confirm(lang_pack.request_refund.replace('{order_id}', orderId)) ) {
				return;
			}
			
			el.unbind('click');
			el.text(lang_pack.process_refund);
			
			var refundUrl = new URL(el.prop('href'));
			var refundNonce = refundUrl.searchParams.get('_wpnonce');
			refundUrl.searchParams.delete('_wpnonce');
			$.ajax({
				url : refundUrl.toString(),
				type : 'POST',
				data: { _wpnonce: refundNonce },
				cache: false,
				dataType: 'json'
			}).done(function(response) {
				
				if (!response.success) {
					var msg = (response.data instanceof Object)?response.data.info:'';
					alert('Cancel Failure\n' + msg);
					restoreClickBinder(el);
					return;
				}
				
				alert('Cancel Success');
				location.reload();
				
			}).fail(function() {
				alert('Cancel Failure');
				restoreClickBinder(el);
			});
		}
		

		$('.my_account_orders .order-actions .cancel').bind('click', requestCancelByCustomer);

	});
		
})(jQuery)
