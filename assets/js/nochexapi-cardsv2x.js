( function ( nochexapi, $ ) {
    'use strict';

    nochexapi.nochexapiCardVars                 = {};
    var nochexapiCardsInProgress         = false;
    var globalPrefix              = '';
    var cardIframeContainerID     = '';
    var cardIframeID              = '';

    function scroll_to_notices( scrollElement ) {
        var offset = 300;
        if ( scrollElement.length ) {
            $( 'html, body' ).animate( {
                scrollTop: ( scrollElement.offset().top-offset )
            }, 1000 );
        }
    }

    function cardsv2Log(obj){
        if( parseInt( nochexapi.nochexapiCardVars.jsLogging ) === 1 ){
	        var e = new Error(obj);
            console.log(obj);
            console.log(e.stack);
        }
    }

    function childFramePost( iFrameId, obj ){
        var iFrame            = document.getElementById( iFrameId );
        var iFrameDoc         = (iFrame.contentWindow || iFrame.contentDocument);
        if( iFrameDoc.document ){
            iFrameDoc         = iFrameDoc.document;
        }

        var event;
        if( typeof window.CustomEvent === "function" ) {
            event             = new CustomEvent( 'frameLogV52', { detail: obj } );
        } else {
            event             = document.createEvent('Event');
            event.initEvent('frameLogV52', true, true);
            event.detail = obj;
        }
        iFrameDoc.dispatchEvent(event);
    }

    function logToParentWindow( e ){
        if( e.detail.hasOwnProperty( 'funcs' ) ){
            for ( var i = 0, len = e.detail.funcs.length; i < len; i++ ) {
                try{
                    let tempfunc    = eval( e.detail.funcs[ i ].name );
                    tempfunc(e.detail.funcs[i].args); 
                }catch( err ){
                    cardsv2Log( err );
                }
            }
        }
    }

    function sendnochexapiVarsObject(args){
        cardsv2Log('sendnochexapiVarsObject => args:');
        var obj = {funcs:[{name:"initialiseCnp", args:[nochexapi.nochexapiCardVars,$('#'+globalPrefix+'checkout_id').val()]}]};
        cardsv2Log(obj);
        childFramePost( cardIframeID, obj );
    }

    function chkCreateAccField(args){
        var nodeList = document.getElementsByName("createaccount");
        if(nodeList.length > 0){
            var obj = {funcs:[
                {name:"adjRgState",args:[$('input[name^="createaccount"]').is(":checked")]}
            ]};
            childFramePost( cardIframeID, obj );
        }
    }

    function nochexapiSetFrameHeight(args){
        if(typeof args[0] === 'number'){
            if(args[0] > 0) {
                //cardsv2Log(args[0] + 'px');
                document.getElementById( cardIframeID ).style.height = args[0] + 'px';
            }
        }
    }

    function nochexapiSetParentBlockUI(args){
        $('body').block({
            message: '<p class="text-align:center">Please wait till payment is processing.<br />It may take some time to process.<br />Don\'t refresh or close or hit back.</p>',
            overlayCSS: {
                background:  '#fff',
                opacity:     1
            },
            css: {
                width:       '50%',
                border:      'none',
                //cursor:      'wait',
                opacity:     1
            }
        });
    }

    function nochexapiUnSetParentBlockUI(args){
        $.unblockUI;
    }

    function pciFormSubmit(iFrameId,paymentContainer){
        cardsv2Log('executing wpwl! =>');
        var iFrame = document.getElementById(iFrameId);
        var iFrameDoc = (iFrame.contentWindow || iFrame.contentDocument);
        if (iFrameDoc.document) iFrameDoc = iFrameDoc.document;
        var obj = {funcs:[
            {name:"executePayment",args:[paymentContainer]}
        ]};
        var event;
        if(typeof window.CustomEvent === "function") {
            event = new CustomEvent('frameLogV52', {detail:obj});
        } else {
            event = document.createEvent('Event');
            event.initEvent('frameLogV52', true, true);
            event.detail = obj;
        }
        iFrameDoc.dispatchEvent(event);
    }

    function unloadWpwlnochexapiCardsv2(){
        if (window.wpwl !== undefined && window.wpwl.unload !== undefined) {
            window.wpwl.unload();
            $('script').each(function () {
                if (this.src.indexOf('static.min.js') !== -1) {
                    $(this).remove();
                }
            });
        }
        $('#nochexapi_alt_cnp_container').empty();
    }

    function instantiateCheckoutIdOrder(){
        cardsv2Log('instantiateCheckoutIdOrder => running!');
        nochexapiCardsInProgress = false;
        $.ajax({
            type: 'post',
            dataType : 'json',
            url: nochexapi.nochexapiCardVars.adminUrl,
            data : {action: globalPrefix + "requestOrderCheckoutId"},
            success: function(response){
                cardsv2Log(response);
                if(response.success === true) {
                    $( '#' + cardIframeContainerID ).empty();
                    $( '#' + globalPrefix + 'checkout_id').val( response.data.uuid );
                    cardsv2Log('set the uuid to: ' + response.data.uuid);
                    $( '#' + cardIframeContainerID ).html('<iframe id="' + cardIframeID + '" src="'+response.data.frameurl+'?v='+Date.now()+'" style="background:#eee;width: 100%; height:275px; border: none;transform: scale(0.9) !important;"></iframe>');
                }
            }
        });
    }

    nochexapi.initCheckoutIdOrder = function(){
	    if($('iframe#' + cardIframeID).length === 0){
            cardsv2Log('Checkout Iframe is initiated');
            instantiateCheckoutIdOrder();
        }
    }

    function completeOrdernochexapiCards(endpoint,checkoutData){
        if(nochexapiCardsInProgress === true){
            cardsv2Log('form in 3d progress!!');
            return;
        }
        nochexapiCardsInProgress = false;
        cardsv2Log('start the post.');
        $('#place_order').after('<p id="nochexapiCardsBtnReplace" style="color:#CCC; text-align:center;">Processing, please wait...</p>');
        $('#place_order').hide();
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        $.ajax({
            type: 'POST',
            url: endpoint,
            contentType: "application/x-www-form-urlencoded; charset=UTF-8",
            enctype: "multipart/form-data",
            data: checkoutData,
            success: function(response){
                cardsv2Log(response);
                document.querySelector('form.woocommerce-checkout').classList.remove('processing');
                if(response.hasOwnProperty('result')){
                    if(response.hasOwnProperty('messages')){
                        $('form.checkout').prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + response.messages + '</div>');
                    }
                    if(response.result === 'success'){
                        if(response.redirect !== false){
                            if(-1 === (response.redirect).indexOf('https://') || -1 === (response.redirect).indexOf('http://') ) {
                                window.location = decodeURI(response.redirect);
                            } else {
                                window.location = response.redirect;
                            }
                        } else if(response.reload !== false){
                            window.location.reload();
                        } else if(response.refresh !== false){
                            $('#'+globalPrefix+'checkout_id').val('');
                            $('body').trigger("update_checkout");
                            $('#nochexapiCardsBtnReplace').remove();
                            $('#place_order').show();
                        } else if(response.hasOwnProperty('pending')){
                            cardsv2Log('end trigger');
                            $('#nochexapiCardsBtnReplace').remove();
                            $('#place_order').show();
                            if(response.hasOwnProperty("frameurl")){
                                $('#payment > ul > li.wc_payment_method.payment_method_'+ nochexapi.nochexapiCardVars.pluginId +' > div').show();
                                $('#'+globalPrefix+'container').empty();
                                $('#'+globalPrefix+'checkout_id').val(response.uuid);
                                cardsv2Log('set the uuid to: ' + response.uuid);
                                $('#'+globalPrefix+'container').html('<iframe id="' + cardIframeID + '" src="'+response.frameurl+'" style="background:#eee;width: 100%; height:275px; border: none;transform: scale(0.9) !important;"></iframe>');
                                scroll_to_notices($('#'+globalPrefix+'container'));
                            }
                            if(response.hasOwnProperty("execute")){
                                cardsv2Log('ready to executePayment');
                                if($('iframe#' + cardIframeID).length){
                                    pciFormSubmit(cardIframeID,"wpwl-container-card");
                                } else {
                                    $('#'+globalPrefix+'checkout_id').val('');
                                    nochexapiCardsInProgress = false;
                                    nochexapi.nochexapiCardsHandoff();
                                }
                            }
                        }
                    } else {
                        var err = $(".woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout");
                        if(err.length > 0 || (err = $(".form.checkout"))){
                            scroll_to_notices(err);
                        }
                        $('form.checkout').find(".input-text, select, input:checkbox").trigger("validate").blur();
                        $('body').trigger("checkout_error");
                        $('#nochexapiCardsBtnReplace').remove();
                        $('#place_order').show();
                    }
                }
            },
            error: function(error){
                cardsv2Log(error);
            }
        });
    }

    function validate_nochexapi_cardsv2_checkout(args){
        $('#nochexapi_cardsv2_container').empty();
        $('#place_order').after('<p id="nochexapiCardsBtnReplace" style="color:#CCC; text-align:center;">Processing, please wait...</p>');
        $('#place_order').hide();
        var generalAlertMsg     = 'Uncertain Response. Please report this to the merchant before reattempting payment. They will need to verify if this transaction is successful.';
        Promise.resolve(
            $.ajax({
                type: 'POST',
                url: nochexapi.nochexapiCardVars.adminUrl,
                data: { action: globalPrefix + 'validate_nochexapi_cardsv2_checkout', resourcePath: args[0]},
                success: function(response) {
                    cardsv2Log(response);
                    if(response.hasOwnProperty("data")){
                        if(response.data.hasOwnProperty("url")){
                            window.location = (response.data.url);
                        } else {
                            alert( "Error(#1):" + generalAlertMsg + "\n" + 'resource:' + args[0] );
                            $('body').trigger("update_checkout");
                            $('#nochexapiCardsBtnReplace').remove();
                            $('#place_order').show();
                        }
                    } else {
                        alert( "Error(#2):" + generalAlertMsg + "\n" + 'resource:' + args[0] );
                        $('body').trigger("update_checkout");
                        $('#nochexapiCardsBtnReplace').remove();
                        $('#place_order').show();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown){
                    alert( "Error(#3):" + generalAlertMsg + "\n" + 'Message::' + textStatus + '->' + errorThrown + "\n" + 'resource:' + args[0] );  
                    cardsv2Log(jqXHR);
                    cardsv2Log(textStatus);
                    cardsv2Log(errorThrown);
                },
            })
        ).then(function(){
            //do something
        }).catch(function(e) {
            alert( "Error(#3):" + generalAlertMsg + "\n" + 'resource:' + args[0] );
            cardsv2Log(e); 
        });
    }

    function progress_tp_cardsv2(args){
        if(args.length === 1){
            cardsv2Log('progress_tp_cardsv2: ' + args[0]);
            tpCardsInProgress = args[0];
        }
    }

    nochexapi.nochexapiCardsHandoff = function() {
        if($('form.woocommerce-checkout').find('input[name^="payment_method"]:checked').val() !== nochexapi.nochexapiCardVars.pluginId){
            return;
        }
        var checkoutData = $("form.woocommerce-checkout").serialize();
        document.querySelector('form.woocommerce-checkout').classList.add('processing');
        completeOrdernochexapiCards(wc_checkout_params.checkout_url,checkoutData);
        return false;
    };

    nochexapi.init = function( options ){
        nochexapi.nochexapiCardVars               = options;
        globalPrefix                = options.pluginPrefix;
        cardIframeContainerID       = globalPrefix + 'iframe_container';
        cardIframeID                = globalPrefix + 'cnpFrame';

        cardsv2Log('checkout endpoint docReady!');
        cardsv2Log(nochexapi.nochexapiCardVars.pluginId + ' v.' + nochexapi.nochexapiCardVars.pluginVer);
        window.document.addEventListener('parentLogV52', logToParentWindow, false);
    }
} )( window.wc_gateway_nochexapi = window.wc_gateway_nochexapi || {}, jQuery );

jQuery( function(){
    var nochexapiGlobalVars    = getnochexapiGlobalVariable();
    wc_gateway_nochexapi.init( nochexapiGlobalVars );
    jQuery(document.body).on('updated_checkout', function() {
        window.wc_gateway_nochexapi.initCheckoutIdOrder();
    });
    
    var checkout_form = jQuery( 'form.woocommerce-checkout' );
    checkout_form.on( 'checkout_place_order', wc_gateway_nochexapi.nochexapiCardsHandoff );
});
