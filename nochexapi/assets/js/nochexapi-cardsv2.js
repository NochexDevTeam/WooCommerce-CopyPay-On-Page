function cardsv2Log(obj){
	if(parseInt(nochexapiCardVars.jsLogging) === 1){
		console.log(obj);
	}
}
function childFramePost(iFrameId,obj){
    var iFrame = document.getElementById(iFrameId);
    var iFrameDoc = (iFrame.contentWindow || iFrame.contentDocument);
    if (iFrameDoc.document) iFrameDoc = iFrameDoc.document;
    var event;
    if(typeof window.CustomEvent === "function") {
        event = new CustomEvent('frameLog', {detail:obj});
    } else {
        event = document.createEvent('Event');
        event.initEvent('frameLog', true, true);
        event.detail = obj;
    }
    iFrameDoc.dispatchEvent(event);
}
function logToParentWindow(e) {
    if(e.detail.hasOwnProperty('funcs')){
        for (var i = 0, len = e.detail.funcs.length; i < len; i++) {
            if(typeof window[e.detail.funcs[i].name] === 'function'){
                window[e.detail.funcs[i].name](e.detail.funcs[i].args);
            } else {
                cardsv2Log(e.detail.funcs[i].name);
            }
        }
    }
}
function chkCreateAccField(args){
    var nodeList = document.getElementsByName("createaccount");
    if(nodeList.length > 0){
        var obj = {funcs:[
            {name:"adjRgState",args:[jQuery('input[name^="createaccount"]').is(":checked")]}
        ]};
        childFramePost('cnpFrame',obj);
    }
}
function nochexapiSetFrameHeight(args){
    if(typeof args[0] === 'number'){
        if(args[0] > 0) {
            cardsv2Log(args[0] + 'px');
            document.getElementById('cnpFrame').style.height = args[0] + 'px';
        }
    }
}
function pciFormSubmit(iFrameId,paymentContainer){
    var iFrame = document.getElementById(iFrameId);
    var iFrameDoc = (iFrame.contentWindow || iFrame.contentDocument);
    if (iFrameDoc.document) iFrameDoc = iFrameDoc.document;
    var obj = {funcs:[
        {name:"executePayment",args:[paymentContainer]}
    ]};
    var event;
    if(typeof window.CustomEvent === "function") {
        event = new CustomEvent('frameLog', {detail:obj});
    } else {
        event = document.createEvent('Event');
        event.initEvent('frameLog', true, true);
        event.detail = obj;
    }
    iFrameDoc.dispatchEvent(event);
}
function fetchOrdernochexapiCards(endpoint,checkoutData){
    cardsv2Log('start the fetch.');
    jQuery('#place_order').after('<p id="nochexapiCardsBtnReplace" style="color:#CCC; text-align:center;">Processing, please wait...</p>');
    jQuery('#place_order').hide();
    fetch(endpoint , {
        method:'POST', 
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        cache: 'no-cache',
        body: checkoutData
    }).then(function(response){
        cardsv2Log(response);
        return response.json();
    }).then(function(json){
        cardsv2Log(json);
        if(json.hasOwnProperty('result')){
            if(json.result === 'success'){
                if(json.redirect !== false){
                    if(-1 === (json.redirect).indexOf('https://') || -1 === (json.redirect).indexOf('http://') ) {
                        window.location = decodeURI(json.redirect);
                    } else {
                        window.location = (json.redirect);
                    }
                }
            }
        }
        return json;
    }).then(function(json){
        jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        return json;
    }).then(function(json){
        if(json.hasOwnProperty('messages')){
            jQuery('form.checkout').prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + json.messages + '</div>');
        }
        return json;
    }).then(function(json){
        document.querySelector('form.woocommerce-checkout').classList.remove('processing');
        if(json.reload !== false){
            window.location.reload();
        } else if(json.refresh !== false){
            jQuery('body').trigger("update_checkout");
            jQuery('#nochexapiCardsBtnReplace').remove();
            jQuery('#place_order').show();
        }
        return json;
    }).then(function(json){
        if(json.result === 'failure'){
            var err = jQuery(".woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout");
            if(err.length > 0 || (err = jQuery(".form.checkout"))){
                jQuery.scroll_to_notices(err);
            }
            jQuery('form.checkout').find(".input-text, select, input:checkbox").trigger("validate").blur();
            jQuery('body').trigger("checkout_error");
            jQuery('#nochexapiCardsBtnReplace').remove();
            jQuery('#place_order').show();
        } else if(json.hasOwnProperty('pending')){
            return json;
        }
        return false;
    }).then(function(json){
        if(json === false) return;
        cardsv2Log('payment trigger');
        pciFormSubmit('cnpFrame',true);
        jQuery.scroll_to_notices(jQuery("#cnpForm"));
        jQuery('#nochexapiCardsBtnReplace').remove();
        jQuery('#place_order').show();
    });
}
var nochexapiCardsHandoff = function() {
    if(jQuery('form.woocommerce-checkout').find('input[name^="payment_method"]:checked').val() !== nochexapiCardVars.pluginId){
        return;
    }
    var checkoutData = jQuery("form.woocommerce-checkout").serialize();
    document.querySelector('form.woocommerce-checkout').classList.add('processing');
    fetchOrdernochexapiCards(wc_checkout_params.checkout_url,checkoutData);
	return false;
};
jQuery(function(){
    console.log('wc_checkout_params =>');
    console.log(wc_checkout_params);
    console.log(nochexapiCardVars.pluginId + ' v.' + nochexapiCardVars.pluginVer);
    cardsv2Log(nochexapiCardVars);
    window.document.addEventListener('parentLog', logToParentWindow, false);
    var checkout_form = jQuery( 'form.woocommerce-checkout' );
    checkout_form.on( 'checkout_place_order', nochexapiCardsHandoff );
});