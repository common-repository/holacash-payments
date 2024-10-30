"use strict";
jQuery(function ($) {
	let fetchedAntiFraudMetaData = "";
	let holacashUserLanguage  = navigator.language || navigator.userLanguage;

	const submitWooForm = () => {
		$("#hc_lang").remove();
		$("#hola_cash_wc_wrapper").append(
			`<input type='hidden' id='hc_lang' name='hc_lang' value="${holacashUserLanguage}" />`
		);
		if (!fetchedAntiFraudMetaData) {
			HolaCashCheckout.getAntiFraudMetadata()
			.then((antiFraudMetaData) => {
				fetchedAntiFraudMetaData = antiFraudMetaData;

				$("#hola_cash_wc_wrapper").append(
					`<input type='hidden' id='hc_uid' name='hc_uid' value="${fetchedAntiFraudMetaData}" />`
				);
				$("#hola_cash_wc_wrapper").closest("form").submit();
			})
			.catch((err) => {
				alert("Some error occured, Try again!");
			});
		} else {
			$("#hola_cash_wc_wrapper").append(
				`<input type='hidden' id='hc_uid' name='hc_uid' value="${fetchedAntiFraudMetaData}" />`
			);
			$("#hola_cash_wc_wrapper").closest("form").submit();
		}
	};

	// payment charge callbacks
	const callbacks = {
		onSuccess: (res) => {
			if (!JSON.parse(res)) {
				return;
			}
			submitWooForm();
		},
		onLanguageChanged: (lang) => {
			holacashUserLanguage = lang
		},

		onAbort: () => {
			$("#hola_cash_wc_wrapper").closest("form").submit();
		},
		onOrderUpdate: () => {
			submitWooForm();
		},

		onError: (err) => {
			let error = `
				<ul class="woocommerce-error" role="alert">
					<li data-id="billing_first_name">
						<strong>Some error occured</strong>
					</li>
				</ul>
			`;
			$(".woocommerce-NoticeGroup-checkout").remove();
			$("form.checkout").prepend(
				`<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">${error}</div>`
			);
		},
	};

	let triggered = false;

	const removeEmptyObjects = (obj) =>
		Object.fromEntries(Object.entries(obj).filter(([_, v]) => v != null));

	const hidePlaceOrderButton = () => {
		triggered = true;
		let payment_method = $('input[name="payment_method"]:checked').val();
		if (payment_method == "hola_cash_wc_gateway") {
			$(document.body).removeClass("hola-cash-selected");
			$(document.body).addClass("hola-cash-selected");
			let order = holacashwc.order_id;
			if (!order.status) {
				alert(order.message);
				return;
			}
		} else {
			$(document.body).removeClass("hola-cash-selected");
		}
	};

	$(document.body).on("payment_method_selected", function (e) {
		hidePlaceOrderButton();
	});

	$(document.body).on("updated_checkout", function (data) {
		let order = holacashwc.order_id;
		if (!order.status) {
			return;
		}
		let order_id = order.order_id;

		let email = $("#billing_email").val();
		let phone = $("#billing_phone").val();
		email = email ? email : "";
		HolaCashCheckout.configure(
			{
				order_id,
				hints: removeEmptyObjects({
					email,
					phone,
				}),
				source: "plugin.woocommerce", // plugin.woocommerce if you're using woocommerce
			},
			callbacks
		);
		$("#hola_cash_wc_wrapper").append(
			`<input type='hidden' value='${order_id}' name='holacash_order_id' /> `
		);
		$("#checkout-button").attr("data-disabled", false);
	});

	/**
	 * To Check if URL is valid or not
	 *
	 * @param url string
	 * @returns
	 */
	function isValidUrl(url) {
		let urlObj;

		try {
			urlObj = new URL(url);
		} catch (_) {
			return false;
		}

		return urlObj.protocol === "https:";
	}

	/**
	 * to trigger 3ds validation on HashChange
	 */
	window.addEventListener(
		"hashchange",
		function (e) {
			// e
			// blocking further execution if has is empty
			if (!window.location.hash) {
				return;
			}
			const urlParams = new URLSearchParams(window.location.hash);
			let returnUrl = urlParams.get("return_url");
			let postReturnUrl = urlParams.get("post_return_url");

			// Cleanup the URL, but this will trigger hashchange
			// so that we need to place condition to check empty hash
			window.location.hash = "";

			// check if valid url is valid or not
			if (!isValidUrl(returnUrl)) {
				return;
			}

			HolaCashCheckout.followUpAuthentication(returnUrl)
				.then((data) => {
					window.location = postReturnUrl;
				})
				.catch((err) => {
					console.log("Authentication with the provider failed");
					window.location = postReturnUrl;
				});
		},
		false
	);

	/**
	 * collect anti fraud meta data to be sent to be used for create charge
	 */
	$(document).ready(function () {
		HolaCashCheckout.getAntiFraudMetadata()
			.then((antiFraudMetaData) => {
				fetchedAntiFraudMetaData = antiFraudMetaData;
			})
			.catch((err) => {
				console.log(err);
				fetchedAntiFraudMetaData = "";
			});
		hidePlaceOrderButton();

		if (triggered) return;

		$(document.body).trigger("updated_checkout");
	});
});
