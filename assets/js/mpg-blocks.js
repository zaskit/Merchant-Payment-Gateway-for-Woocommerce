(function(){
    'use strict';

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement = window.wp.element.createElement;
    var decodeEntities = window.wp.htmlEntities.decodeEntities;

    // All 5 gateway IDs
    var gateways = [
        'mpg_vprocessor_2d',
        'mpg_vprocessor_3d',
        'mpg_eprocessor_2d',
        'mpg_eprocessor_3d',
        'mpg_eprocessor_hosted'
    ];

    // Field name prefixes per gateway
    var fieldPrefixes = {
        mpg_vprocessor_2d: 'mpg_vp2d',
        mpg_vprocessor_3d: 'mpg_vp3d',
        mpg_eprocessor_2d: 'mpg_ep2d',
        mpg_eprocessor_3d: 'mpg_ep3d',
        mpg_eprocessor_hosted: null // No card fields
    };

    // Different form configs per gateway type
    var formConfigs = {
        mpg_vprocessor_2d: {
            fields: [
                { name: 'card_name', label: 'Cardholder Name', placeholder: 'Name on card', type: 'text', autocomplete: 'cc-name' },
                { name: 'card_number', label: 'Card Number', placeholder: '0000 0000 0000 0000', type: 'text', autocomplete: 'cc-number', maxLength: 23, inputMode: 'numeric' },
            ],
            row: [
                { name: 'expiry', label: 'Expiry', placeholder: 'MM / YY', maxLength: 7, inputMode: 'numeric', autocomplete: 'cc-exp' },
                { name: 'cvv', label: 'CVC', placeholder: '•••', maxLength: 3, inputMode: 'numeric', autocomplete: 'cc-csc' },
            ]
        },
        mpg_vprocessor_3d: {
            fields: [
                { name: 'card_holder_name', label: 'Card Holder Name', placeholder: 'John Doe', type: 'text', autocomplete: 'cc-name' },
                { name: 'card_number', label: 'Card Number', placeholder: '•••• •••• •••• ••••', type: 'text', autocomplete: 'cc-number', maxLength: 19, inputMode: 'numeric' },
            ],
            row: [
                { name: 'card_expiry_month', label: 'Expiry Month', placeholder: 'MM', maxLength: 2, inputMode: 'numeric', autocomplete: 'cc-exp-month' },
                { name: 'card_expiry_year', label: 'Expiry Year', placeholder: 'YY', maxLength: 2, inputMode: 'numeric', autocomplete: 'cc-exp-year' },
            ],
            cvv: { name: 'card_cvv', label: 'CVV', placeholder: 'CVV', maxLength: 4, inputMode: 'numeric', autocomplete: 'cc-csc' }
        },
        mpg_eprocessor_2d: {
            fields: [
                { name: 'card_name', label: 'Card Holder Name', placeholder: 'John Doe', type: 'text', autocomplete: 'cc-name' },
                { name: 'card_number', label: 'Card Number', placeholder: '0000 0000 0000 0000', type: 'text', autocomplete: 'cc-number', maxLength: 23, inputMode: 'numeric' },
            ],
            row: [
                { name: 'expiry', label: 'Expiry', placeholder: 'MM / YY', maxLength: 7, inputMode: 'numeric', autocomplete: 'cc-exp' },
                { name: 'cvv', label: 'CVC', placeholder: '•••', maxLength: 4, inputMode: 'numeric', autocomplete: 'cc-csc' },
            ]
        },
        mpg_eprocessor_3d: {
            fields: [
                { name: 'card_name', label: 'Card Holder Name', placeholder: 'John Doe', type: 'text', autocomplete: 'cc-name' },
                { name: 'card_number', label: 'Card Number', placeholder: '0000 0000 0000 0000', type: 'text', autocomplete: 'cc-number', maxLength: 23, inputMode: 'numeric' },
            ],
            row: [
                { name: 'expiry', label: 'Expiry', placeholder: 'MM / YY', maxLength: 7, inputMode: 'numeric', autocomplete: 'cc-exp' },
                { name: 'cvv', label: 'CVC', placeholder: '•••', maxLength: 4, inputMode: 'numeric', autocomplete: 'cc-csc' },
            ]
        }
    };

    function formatCardNumber(val) {
        var d = val.replace(/\D/g,'').substring(0,16);
        var parts = d.match(/.{1,4}/g);
        return parts ? parts.join(' ') : d;
    }

    function formatExpiry(val) {
        var d = val.replace(/\D/g,'').substring(0,4);
        if(d.length >= 3) return d.substring(0,2) + ' / ' + d.substring(2);
        return d;
    }

    function numericOnly(val, max) {
        return val.replace(/\D/g,'').substring(0, max);
    }

    gateways.forEach(function(gatewayId){
        var dataVar = window['mpg_blocks_data_' + gatewayId];
        if(!dataVar) return;

        var prefix = fieldPrefixes[gatewayId];
        var config = formConfigs[gatewayId] || null;

        var Content = function(props){
            var eventRegistration = props.eventRegistration;
            var emitResponse = props.emitResponse;
            var onPaymentSetup = eventRegistration.onPaymentSetup;

            var stateRef = window.wp.element.useRef({});

            window.wp.element.useEffect(function(){
                if(!onPaymentSetup) return;
                var unsub = onPaymentSetup(function(){
                    var paymentData = {};
                    var s = stateRef.current;
                    for(var key in s){
                        paymentData[prefix + '_' + key] = s[key] || '';
                    }
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: { paymentMethodData: paymentData }
                    };
                });
                return unsub;
            },[onPaymentSetup, emitResponse]);

            // No card fields for hosted
            if(!prefix || !config){
                return createElement('div', { className: 'mpg-hosted-block-notice' },
                    createElement('p', null, decodeEntities(dataVar.description || 'You will be redirected to a secure payment page.'))
                );
            }

            function onInput(fieldName, formatter){
                return function(e){
                    var val = formatter ? formatter(e.target.value) : e.target.value;
                    e.target.value = val;
                    stateRef.current[fieldName] = val;
                };
            }

            var elements = [];

            // Description
            if(dataVar.description){
                elements.push(createElement('p', {key:'desc', style:{marginBottom:'12px',fontSize:'14px',color:'#6b7280'}}, decodeEntities(dataVar.description)));
            }

            // Full-width fields
            if(config.fields){
                config.fields.forEach(function(f, i){
                    var fmt = null;
                    if(f.name.indexOf('card_number') !== -1) fmt = formatCardNumber;
                    elements.push(
                        createElement('div', {key:'f'+i, className:'mpg-block-field'},
                            createElement('label', null, f.label),
                            createElement('input', {
                                type: f.type || 'text',
                                placeholder: f.placeholder,
                                maxLength: f.maxLength || undefined,
                                inputMode: f.inputMode || undefined,
                                autoComplete: f.autocomplete || undefined,
                                onInput: onInput(f.name, fmt)
                            })
                        )
                    );
                });
            }

            // Row fields (expiry + CVV)
            if(config.row){
                var rowChildren = config.row.map(function(f, i){
                    var fmt = null;
                    if(f.name === 'expiry') fmt = formatExpiry;
                    else if(f.name === 'cvv' || f.name === 'card_cvv') fmt = function(v){ return numericOnly(v, f.maxLength || 4); };
                    else if(f.name === 'card_expiry_month') fmt = function(v){ return numericOnly(v,2); };
                    else if(f.name === 'card_expiry_year') fmt = function(v){ return numericOnly(v,2); };
                    return createElement('div', {key:'r'+i, className:'mpg-block-field'},
                        createElement('label', null, f.label),
                        createElement('input', {
                            type: 'text',
                            placeholder: f.placeholder,
                            maxLength: f.maxLength || undefined,
                            inputMode: f.inputMode || 'numeric',
                            autoComplete: f.autocomplete || undefined,
                            onInput: onInput(f.name, fmt)
                        })
                    );
                });
                elements.push(createElement('div', {key:'row', className:'mpg-block-row'}, rowChildren));
            }

            // Extra CVV for VP3D (separate from row)
            if(config.cvv){
                var cf = config.cvv;
                elements.push(
                    createElement('div', {key:'cvv', className:'mpg-block-field'},
                        createElement('label', null, cf.label),
                        createElement('input', {
                            type: 'text',
                            placeholder: cf.placeholder,
                            maxLength: cf.maxLength,
                            inputMode: cf.inputMode || 'numeric',
                            autoComplete: cf.autocomplete || undefined,
                            onInput: onInput(cf.name, function(v){ return numericOnly(v, cf.maxLength); })
                        })
                    )
                );
            }

            // Secure badge
            elements.push(
                createElement('div', {key:'badge', className:'mpg-block-secure-badge'},
                    createElement('span', null, '🔒 Secured with 256-bit encryption')
                )
            );

            return createElement('div', { className: 'mpg-block-card-form' }, elements);
        };

        var Label = function(props){
            var icons = (dataVar.icons || []).map(function(ic, i){
                return createElement('img', {key:i, src:ic.src, alt:ic.alt, style:{maxHeight:'24px',marginLeft:'6px',verticalAlign:'middle',display:'inline-block'}});
            });
            return createElement('span', {style:{display:'inline-flex',alignItems:'center',gap:'4px'}},
                decodeEntities(dataVar.title || 'Pay by Card'),
                icons
            );
        };

        registerPaymentMethod({
            name: gatewayId,
            label: createElement(Label),
            content: createElement(Content),
            edit: createElement(Content),
            canMakePayment: function(){ return true; },
            ariaLabel: dataVar.title || 'Pay by Card',
            supports: { features: dataVar.supports || ['products'] }
        });
    });
})();
