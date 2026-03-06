(function(){
    'use strict';

    function formatCardNumber(input){
        input.addEventListener('input',function(){
            var v = this.value.replace(/\D/g,'').substring(0,16);
            var parts = v.match(/.{1,4}/g);
            this.value = parts ? parts.join(' ') : v;
        });
    }

    function formatExpiry(input){
        input.addEventListener('input',function(){
            var v = this.value.replace(/\D/g,'').substring(0,4);
            if(v.length>=3){
                this.value = v.substring(0,2)+' / '+v.substring(2);
            } else {
                this.value = v;
            }
        });
    }

    function limitNumeric(input,max){
        input.addEventListener('input',function(){
            this.value = this.value.replace(/\D/g,'').substring(0,max);
        });
    }

    function init(){
        // Card number fields (space-formatted)
        document.querySelectorAll([
            'input[name="mpg_vp2d_card_number"]',
            'input[name="mpg_vp3d_card_number"]',
            'input[name="mpg_ep2d_card_number"]',
            'input[name="mpg_ep3d_card_number"]'
        ].join(',')).forEach(formatCardNumber);

        // Expiry fields (MM / YY format)
        document.querySelectorAll([
            'input[name="mpg_vp2d_expiry"]',
            'input[name="mpg_ep2d_expiry"]',
            'input[name="mpg_ep3d_expiry"]'
        ].join(',')).forEach(formatExpiry);

        // CVV fields (numeric only, max 4)
        document.querySelectorAll([
            'input[name="mpg_vp2d_cvv"]',
            'input[name="mpg_vp3d_card_cvv"]',
            'input[name="mpg_ep2d_cvv"]',
            'input[name="mpg_ep3d_cvv"]'
        ].join(',')).forEach(function(el){ limitNumeric(el,4); });

        // VP3D separate month/year fields
        document.querySelectorAll('input[name="mpg_vp3d_card_expiry_month"]').forEach(function(el){ limitNumeric(el,2); });
        document.querySelectorAll('input[name="mpg_vp3d_card_expiry_year"]').forEach(function(el){ limitNumeric(el,2); });
    }

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',init);
    } else {
        init();
    }

    // Re-init on WooCommerce checkout update
    if(typeof jQuery !== 'undefined'){
        jQuery(document.body).on('updated_checkout payment_method_selected',function(){
            setTimeout(init,200);
        });
    }
})();
