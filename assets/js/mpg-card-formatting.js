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

    function isMastercard(digits){
        if(digits.length<2) return null; // not enough digits yet
        var two = parseInt(digits.substring(0,2),10);
        if(two>=51 && two<=55) return true;
        if(digits.length>=4){
            var four = parseInt(digits.substring(0,4),10);
            if(four>=2221 && four<=2720) return true;
        }
        return false;
    }

    function setupVp2dMastercardCheck(input){
        var notice = document.createElement('div');
        notice.className = 'mpg-mc-notice';
        notice.style.cssText = 'color:#b91c1c;font-size:13px;margin-top:4px;display:none;';
        notice.textContent = 'Only Mastercard is accepted on this gateway. Please use a Mastercard.';
        input.parentNode.appendChild(notice);

        input.addEventListener('input',function(){
            var digits = this.value.replace(/\D/g,'');
            if(digits.length<2){ notice.style.display='none'; return; }
            var mc = isMastercard(digits);
            if(mc===false){
                notice.style.display='block';
                input.style.borderColor='#b91c1c';
            } else {
                notice.style.display='none';
                input.style.borderColor='';
            }
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

        // VP2D Mastercard-only check
        document.querySelectorAll('input[name="mpg_vp2d_card_number"]').forEach(setupVp2dMastercardCheck);

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
