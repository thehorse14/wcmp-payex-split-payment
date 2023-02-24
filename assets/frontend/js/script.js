(function() {
  var data = payex_wc_checkout_vars;
  if(data === 'checkoutForm') {
    document.getElementById("checkoutForm").submit();
  } else {
    var setDisabled = function(id, state) {
      if (typeof state === 'undefined') {
        state = true;
      }
      var elem = document.getElementById(id);
      if (state === false) {
        elem.removeAttribute('disabled');
      } else {
        elem.setAttribute('disabled', state);
      }
    };

    // Payment was closed without handler getting called
    data.modal = {
      ondismiss: function() {
        setDisabled('btn-payex', false);
      },
    };

    data.handler = function(payment) {
      setDisabled('btn-payex-cancel');
      var successMsg = document.getElementById('msg-payex-success');
      successMsg.style.display = 'block';
      document.getElementById('payex_payment_id').value =
        payment.payex_payment_id;
      document.getElementById('payex_signature').value =
        payment.payex_signature;
      document.payexform.submit();
    };

    var payexCheckout = new Payex(data);

    // global method
    function openCheckout() {
      // Disable the pay button
      setDisabled('btn-payex');
      payexCheckout.open();
    }

    function addEvent(element, evnt, funct) {
      if (element.attachEvent) {
        return element.attachEvent('on' + evnt, funct);
      } else return element.addEventListener(evnt, funct, false);
    }

    if (document.readyState === 'complete') {
      addEvent(document.getElementById('btn-payex'), 'click', openCheckout);
      openCheckout();
    } else {
      document.addEventListener('DOMContentLoaded', function() {
        addEvent(document.getElementById('btn-payex'), 'click', openCheckout);
        openCheckout();
      });
    }
  }
})();
