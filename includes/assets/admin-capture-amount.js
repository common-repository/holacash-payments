jQuery(document).on("click", ".capture-pending-charge", function () {
	jQuery("#hc_capture_container").toggle(100, "swing");
});

jQuery(document).on("click", "#hc_capture_container button", function (e) {
	e.preventDefault();
	let btn = jQuery(this);
	let prevText = btn.text();
	btn.attr("disabled", true);
	btn.text("processing");
	let captureContainer = jQuery(this).closest("#hc_capture_container");
	let captureAmount = captureContainer.find("input").val();
	if (!captureAmount) {
		alert("Please enter the amount");
	} else {
		captureAmount = parseFloat(captureAmount);
		jQuery.ajax({
			url: hc_capture.ajaxUrl,
			method: "POST",
			data: {
				order_id: hc_capture.order_id,
				amount: captureAmount,
				action: hc_capture.action,
				nonce: hc_capture.nonce,
			},
			success: function (resp) {
				if (resp.status) {
					window.location.href = "";
				} else {
					alert(resp.message);
				}
			},
			error: function (err) {
				alert("Some error occured");
			},
			complete: function () {
				btn.attr("disabled", false);
				btn.text(prevText);
			},
		});
	}
});
