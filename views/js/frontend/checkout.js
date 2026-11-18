/**
 * wallee Prestashop
 *
 * This Prestashop module enables to process payments with wallee (https://www.wallee.com).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2026 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */
jQuery(function ($) {

    var wallee_checkout = {


        payment_methods : {},
        configuration_id: null,
        cartHash: null,
        processing: false,
        active_pay_button: null,


        init : function () {
            if ($('#wallee-iframe-handler').length) {
                $(".wallee-method-data").each(function (key, element) {
                    $("#"+psId+"-container").parent().remove();

                });
                return;
            }
            this.add_listeners();
            this.bind_rendered_payment_options();
            this.add_opc_listeners();
            this.trigger_initial_selection();
        },

        // Classic checkout can render one of our payment options already checked on first load -
        // cart is free, we're the only payment option configured, or it's the one the customer had
        // selected on a previous visit (see classic theme payment.tpl's "checked" condition) -
        // without the browser ever firing a "change" event, which is what payment_method_click()/
        // register_method() rely on to set up the iframe widget (or mark a redirect method).
        // Without that, this.payment_methods[method_id] stays undefined and clicking "Pay" throws
        // inside process_submit_button(), leaving the pay button disabled behind the loading
        // spinner forever. Dispatching a synthetic "change" here - mirroring what
        // ps_onepagecheckout already does after it (re-)renders its own payment-option list -
        // re-enters the exact same code path a genuine selection would. Scoped to our own method
        // so it can't affect other payment modules that happen to be pre-selected. Harmless on OPC:
        // nothing is rendered yet the first time this runs there (see
        // bind_rendered_payment_options()'s comment), so get_selected_payment_method() finds
        // nothing and this is a no-op.
        trigger_initial_selection : function () {
            var current_method = this.get_selected_payment_method();
            if (this.is_own_method_name(current_method.data('module-name'))) {
                current_method.trigger('change');
            }
        },

        // Binds the currently-rendered payment-option markup. Must be re-entrant: besides the
        // initial page load, this also gets called every time PrestaShop 9.2's native One Page
        // Checkout (module ps_onepagecheckout) re-fetches and replaces the whole payment-option
        // list, which it does asynchronously (the list starts in an "awaiting address" state and is
        // only rendered once the address is complete), so nothing is present yet the first time this
        // runs on OPC.
        bind_rendered_payment_options : function () {
            var self = this;

            // OPC fully replaces the payment-list DOM on every refresh, so drop any handler whose
            // container no longer exists rather than risk reusing a reference to a dead iframe.
            $.each(this.payment_methods, function (method_id) {
                if (!$('#wallee-' + method_id).length) {
                    delete self.payment_methods[method_id];
                }
            });

            $(".wallee-method-data").each(function (key, element) {
                var infoId = $(element).closest(
                    '.js-additional-information, .payment-option__additional-information, .additional-information, div[id$="-additional-information"]'
                ).attr('id');
                var psId = infoId.substring(0, infoId.indexOf('-additional-information'));
                var psContainer = $('#'+psId+'-container');
                var $label = psContainer.find('label[for="' + psId + '"]').first();
                $label.find('img').addClass('wallee-image');
                psContainer.addClass('wallee-payment-option');
                var fee = $(element).closest("div.additional-information").find(".wallee-payment-fee");
                $label.append(fee);
                $("#"+psId).data("wallee-method-id", $(element).data("method-id")).data("wallee-configuration-id", $(element).data("configuration-id"));
            });

            $('form.wallee-amount-error').each(function () {
                this.submit = function (evt) {
                    //This is the info message and sould not be used as method
                }
            });
        },

        add_listeners : function () {
            $(document)
                .off("change.wallee", "input[name='payment-option']")
                .on(
                    "change.wallee", "input[name='payment-option']",
                    { self: this },
                    this.payment_method_click
                )
                .off("click.wallee", ".wallee-pay-button")
                .on(
                    "click.wallee", ".wallee-pay-button",
                    { self: this },
                    this.pay_button_click
                );
        },

        // Is the given PaymentOption::setModuleName() value one of ours? Every method
        // configuration gets its own "wallee-{methodId}" name (see
        // hookPaymentOptions()/hookDisplayPaymentByBinaries() on the PHP side) so PrestaShop's
        // generic binary-payment machinery can tell our methods apart - this intentionally
        // excludes the "wallee-error" amount-error placeholder option, which has
        // no widget/method behind it.
        is_own_method_name : function (moduleName) {
            return typeof moduleName === 'string' && /^wallee-\d+$/.test(moduleName);
        },

        // No-op on classic checkout / PS8, where "prestashop" either isn't present or never emits
        // this event: only ps_onepagecheckout's JS fires it, after it swaps in a freshly-fetched
        // payment-option list.
        add_opc_listeners : function () {
            var self = this;
            if (typeof window.prestashop === 'undefined' || typeof window.prestashop.on !== 'function') {
                return;
            }
            window.prestashop.on('opcPaymentMethodsUpdated', function () {
                self.bind_rendered_payment_options();
            });
        },

        payment_method_click : function (event) {
            var self = event.data.self;
            // ps_onepagecheckout re-dispatches a synthetic "change" on the already-checked radio
            // right after it swaps in a freshly-fetched payment-option list (to re-arm Core's own
            // binary-visibility/terms-gating logic), and it does so BEFORE emitting the
            // opcPaymentMethodsUpdated event bind_rendered_payment_options() normally runs on - so
            // that binding can't be assumed to have already run by the time this fires. Re-run it
            // here too; it is idempotent and cheap.
            self.bind_rendered_payment_options();
            var current_method = self.get_selected_payment_method();
            var postData;
            if (self.is_own_method_name(current_method.data('module-name'))) {
                postData = "methodId="+current_method.data("wallee-method-id");
            }
                $.ajax({
                    type: 'POST',
                    url: walleeCheckoutUrl,
                    data: postData,
                    dataType: "json",
                    success: function (response, textStatus, jqXHR) {
                        if (response.result === 'success') {
                            $("#js-checkout-summary").fadeOut("slow", function () {
                                var div = $(response.preview).hide();
                                $(this).replaceWith(div);
                                $("#js-checkout-summary").fadeIn("slow");
                            });
                            $("#order-items").fadeOut("slow", function () {
                                var confirmation = $(response.summary).find("#order-items");
                                $(confirmation).hide();
                                $(this).replaceWith(confirmation);
                                $("#order-items").fadeIn("slow");
                            });
                            self.cartHash = response.cartHash
                        } else {
                            window.location.href = window.location.href;
                        }
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        window.location.href = window.location.href;
                        return;
                    }
                });
            
            if (self.is_own_method_name(current_method.data('module-name'))) {
                self.register_method(current_method.data("wallee-method-id"), current_method.data("wallee-configuration-id"), "wallee-"+current_method.data("wallee-method-id"));
            }
        },

        // Click handler for the button hookDisplayPaymentByBinaries() renders. Runs the same
        // validate -> create order -> widget submit sequence process_submit_button() already
        // drove (previously triggered by hijacking the generic "Place order" button's form
        // submission - see create_order()/process_validation() below, both unchanged). On
        // PrestaShop 9's One Page Checkout, first await its own pre-payment submission
        // (address/terms/etc. persistence) via the integration point it exposes for binary
        // payment modules, so our own order creation reads freshly-saved checkout data; this is
        // a no-op on classic checkout / PS8, where that API doesn't exist. The button is locked
        // synchronously, before any of this runs, so a second click can't re-enter while the
        // first is still in flight.
        pay_button_click : function (event) {
            event.preventDefault();
            var self = event.data.self;
            var $button = $(event.currentTarget);
            if (self.processing || $button.prop('disabled') || $button.closest('.opc-binary-disabled').length) {
                return;
            }
            var method_id = $button.data('method-id');
            self.active_pay_button = $button;
            self.disable_pay_button();

            var afterOpcSubmit = function () {
                // Address data may have just been persisted by OPC's own submit above, so the
                // cartHash computed on the last payment-method selection can be stale (it hashes
                // in address date_upd - see Helper.php calculateCartHash()). Refresh it before
                // the orderability check / order creation below.
                self.refresh_cart_hash(method_id, function () {
                    self.check_cart_still_orderable(function () {
                        self.process_submit_button(method_id);
                    });
                });
            };

            if (window.ps_onepagecheckout && typeof window.ps_onepagecheckout.submitBeforePayment === 'function') {
                self.run_opc_submit_before_payment(afterOpcSubmit);
            } else {
                self.check_cart_still_orderable(function () {
                    self.process_submit_button(method_id);
                });
            }
        },

        // OPC's own submitBeforePayment() (ps_onepagecheckout's views/js/opc-submit.js) has no
        // success/failure return value: every branch - precondition invalid, server-rejected,
        // "reload", or a genuine network error - resolves the promise the same way, except for
        // the one case where the checkout form itself can't be found, which rejects. The function
        // does emit "handleError"/"opcSubmitFailed" on a server-rejected or network-error submit,
        // and renders inline field errors (.is-invalid / [data-opc-field-error]) on a validation
        // failure, so those are the only externally observable failure signals available to us; a
        // "reload" failure navigates the page away without emitting either, which can't be
        // distinguished from success from out here.
        //
        // Field errors whose name has no matching visible field on the page - and the
        // carrier/payment-selection preconditions checked before the request is even sent - don't
        // get a field marker or an event either: OPC falls back to a page-level
        // ".js-opc-validation-error" alert instead (opc-validation-alert.js). That alert element is
        // always removed and recreated on every render (clearValidationAlert() + a fresh element),
        // so comparing its identity before/after the call tells a freshly-rendered alert apart from
        // one already left over from an earlier attempt.
        run_opc_submit_before_payment : function (onSuccess) {
            var self = this;
            var failed = false;
            var markFailed = function () {
                failed = true;
            };
            // "handleError" is a generic event also used outside the submit flow, so only treat it
            // as a submitBeforePayment() failure when its payload identifies itself as one
            // (emitSubmitFailure()/emitSubmitRuntimeError() in opc-submit.js always set
            // eventType: 'opcSubmit'); "opcSubmitFailed" is specific to this call already.
            var markFailedIfOwnSubmit = function (data) {
                if (data && data.eventType === 'opcSubmit') {
                    failed = true;
                }
            };
            var hasPrestashopEvents = window.prestashop && typeof window.prestashop.on === 'function';
            if (hasPrestashopEvents) {
                window.prestashop.on('handleError', markFailedIfOwnSubmit);
                window.prestashop.on('opcSubmitFailed', markFailed);
            }
            var cleanup = function () {
                if (hasPrestashopEvents && typeof window.prestashop.off === 'function') {
                    window.prestashop.off('handleError', markFailedIfOwnSubmit);
                    window.prestashop.off('opcSubmitFailed', markFailed);
                }
            };
            var previousValidationAlert = document.querySelector('.js-opc-validation-error');
            window.ps_onepagecheckout.submitBeforePayment().then(
                function () {
                    cleanup();
                    var currentValidationAlert = document.querySelector('.js-opc-validation-error');
                    var hasNewValidationAlert = currentValidationAlert !== null
                        && currentValidationAlert !== previousValidationAlert;
                    // Scoped to OPC's own form (#opc-form, see opc-submit.js's
                    // OPC_SELECTORS.opc.form) - OPC itself only ever checks field errors within
                    // that form (hasFlaggedFieldErrors()). Checking the whole page would let a
                    // stray .is-invalid left over from an unrelated widget (e.g. the address-edit
                    // modal, opc-address-modal.js) silently block payment.
                    if (failed || hasNewValidationAlert
                        || $('#opc-form .is-invalid, #opc-form [data-opc-field-error="1"]').length) {
                        self.enable_pay_button();
                        return;
                    }
                    onSuccess();
                },
                function () {
                    cleanup();
                    self.enable_pay_button();
                }
            );
        },

        // Re-fetches cartHash from the checkout controller. Needed after OPC's own pre-payment
        // submission, which may have just persisted an address edit the customer made after
        // selecting the payment method. Fails open on error: order.php re-validates the hash
        // server-side regardless and rejects with reload:true if it's still stale, same as before
        // this existed.
        //
        // Deliberate trade-off: because this runs right before order creation, it also absorbs any
        // other cart change made in the meantime (e.g. from another tab) instead of catching it -
        // the "your cart changed, please confirm" reload only fires for drift happening after this
        // point now, not since the customer last saw the total on this page. That's accepted
        // because it's a consent/UX safeguard, not a payment-integrity one: order.php always builds
        // the order from $cart->getOrderTotal() on the live cart (see postProcess()), so the
        // customer is never charged a stale or incorrect amount either way.
        refresh_cart_hash : function (method_id, callback) {
            var self = this;
            $.ajax({
                type: 'POST',
                url: walleeCheckoutUrl,
                data: "methodId=" + method_id,
                dataType: "json",
                success: function (response) {
                    if (response && response.result === 'success' && response.cartHash) {
                        self.cartHash = response.cartHash;
                    }
                    callback();
                },
                error: function () {
                    callback();
                }
            });
        },

        // Mirrors Core's own confirm() (themes/_core/js/checkout-payment.js), which always
        // re-checks stock/orderability immediately before submitting the payment form. The binary
        // button bypassed this entirely before; it must behave the same as Core's generic button
        // here, on both classic checkout and OPC.
        check_cart_still_orderable : function (onOrderable) {
            var self = this;
            if (typeof window.prestashop === 'undefined' || !window.prestashop.urls || !window.prestashop.urls.pages) {
                onOrderable();
                return;
            }
            $.post(window.prestashop.urls.pages.order, {ajax: 1, action: 'checkCartStillOrderable'})
                .done(function (response) {
                    var handler = window.prestashop.checkout && window.prestashop.checkout.onCheckOrderableCartResponse;
                    if (typeof handler === 'function') {
                        if (handler(response, {})) {
                            // Core's own handler already redirected the customer away (out of stock).
                            return;
                        }
                    } else if (response && response.errors === true) {
                        window.location.href = response.cartUrl || window.location.href;
                        return;
                    }
                    onOrderable();
                })
                .fail(function () {
                    self.enable_pay_button(walleeMsgJsonError);
                });
        },

        get_selected_payment_method : function () {
               return $("input[name='payment-option']:checked");
        },

        register_method : function (method_id, configuration_id, container_id) {
            //if redirect was enabled
            if ($('#wallee-iframe-possible-' + method_id).val() == 1) {
                $('#wallee-loader-'+method_id).remove();
                this.payment_methods[method_id] = {
                    configuration_id : configuration_id,
                    container_id : container_id,
                    handler: null
                };
                return;
            }

            if (typeof this.payment_methods[method_id] != 'undefined'
                && $('#' + container_id).find("iframe").length > 0) {
                return;
            }
            var self = this;
            this.payment_methods[method_id] = {
                configuration_id : configuration_id,
                container_id : container_id,
                handler : window.walleeIFrameCheckoutHandler(configuration_id)
            };
            this.payment_methods[method_id].handler
                .setValidationCallback(function (validation_result) {
                    self.process_validation(method_id, validation_result);
                });
            this.payment_methods[method_id].handler.setInitializeCallback(function () {
                $('#wallee-loader-'+method_id).remove();
                $('#wallee-iframe-possible-'+method_id).remove();
            });

            this.payment_methods[method_id].handler
                .create(self.payment_methods[method_id].container_id);
        },

        // The button lock (processing flag + disable) is already held by the caller
        // (pay_button_click) by the time this runs - see its comment for why that needs to happen
        // synchronously on click rather than here.
        process_submit_button : function (method_id) {
            if (this.payment_methods[method_id].handler == null) {
                this.create_order(method_id);
                return;
            }
            this.payment_methods[method_id].handler.validate();
        },

        disable_pay_button : function () {
            $('#payment-confirmation button').prop('disabled', true);
            if (this.active_pay_button) {
                this.active_pay_button.prop('disabled', true);
            }
            this.processing = true;
            this.show_loading_spinner();
        },

        enable_pay_button : function (errors) {
            $('#payment-confirmation button').prop('disabled', false);
            if (this.active_pay_button) {
                this.active_pay_button.prop('disabled', false);
            }
            this.processing = false;
            this.hide_loading_spinner();
            this.remove_existing_errors();
            if (errors) {
                this.show_new_errors(errors);
            }
        },

        process_validation : function (method_id, validation_result) {
            if (validation_result.success) {
                this.create_order(method_id);
            } else {
                this.enable_pay_button(validation_result.errors);
            }
        },

        create_order : function (method_id) {
            var form = $('#wallee-'+method_id).closest('form.wallee-payment-form');
            var self = this;
            $.ajax({
                type:       'POST',
                dataType:   'json',
                url:        form.attr('action'),
                data:       form.serialize() + '&methodId=' + method_id + '&cartHash=' + this.cartHash,
                success: function (response) {
                    if ( response.result === 'success' ) {
                            self.payment_methods[method_id].handler.submit();
                            return;
                    } else if (response.result ==='redirect') {
                        window.location.href = response.redirect;
                        return;
                    } else if ( response.result === 'failure' ) {
                        if (response.reload === 'true' ) {
                            window.location.href = window.location.href;
                            return;
                        } else if (response.redirect) {
                            window.location.href = response.redirect;
                            return;
                        }
                    }
                    self.enable_pay_button(walleeMsgJsonError);
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    self.enable_pay_button(walleeMsgJsonError);
                }
            });


        },

        remove_existing_errors : function () {
            $("#notifications").empty();
        },

        show_new_errors : function (messages) {
            if ( typeof messages == 'undefined') {
                return;
            }
            $("#notifications").append('<div class="container"><article class="alert alert-danger" role="alert" data-alert="danger"><ul id="wallee-errors"></ul></article></div>');
            if (messages.constructor === Array) {
                for (var i = 0; i < messages.length; i++) {
                    $("#wallee-errors").append("<li>"+messages[i]+"</li>");
                }
            } else if (typeof messages == 'object') {
                for (var prop in messages) {
                    if (messages.hasOwnProperty(prop)) {
                        $("#wallee-errors").append("<li>"+messages[prop]+"</li>");
                    }
                }
            } else {
                $("#wallee-errors").append("<li>"+messages+"</li>");
            }
        },

        show_loading_spinner : function () {
            $("#checkout-payment-step").css({position:  "relative"});
            $("#checkout-payment-step").append('<div class="wallee-blocker" id="wallee-blocker"><div class="wallee-loader"></div></div>')
        },

        hide_loading_spinner : function () {
            $("#checkout-payment-step").css({position:  ""});
            $("#wallee-blocker").remove();
        }
    };

    wallee_checkout.init();

});
