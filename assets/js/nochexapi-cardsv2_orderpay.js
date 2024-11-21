function cardsv2Log(obj){
	if(parseInt(nochexapiCardVars.jsLogging) === 1){
		console.log(obj);
	}
}
function addRemClassSelector(elemSelector,classAdd,classRemove){
    var elem = document.querySelector(elemSelector);
    for (var i = 0, len = classAdd.length; i < len; i++) {
        elem.classList.add(classAdd[i]);
    }
}
function checkWpwlContainers(){
    if(jQuery(".wpwl-group-registration").length){
        jQuery('#cnpNav').append('<button type="button" class="cnpTarget" data-target="wpwl-container-registration"><i class="fa fa-shopping-bag"></i>&nbsp; Use saved card</button>');
        jQuery('#cnpNav').append('<button type="button" class="cnpTarget" data-target="wpwl-container-card"><i class="fa fa-credit-card"></i>&nbsp; New card</button>');
        jQuery('.wpwl-container-card').hide();
    } else {
        jQuery('#cnpNav').empty();
        jQuery('.wpwl-container-card').show();
    }
	if(jQuery('form#order_review').find('input[name^="payment_method"]:checked').data("registrationid") !== undefined){
		jQuery('#cnpNav').empty();   
	} else if(nochexapiCardVars.slickOneClick === '1') {
		jQuery('#cnpNav').empty();
		jQuery('.wpwl-container-registration').hide();
        jQuery('.wpwl-container-card').show();
	}
}
function displayImgFooternochexapi(brands){
    var brandArr = brands.split(" ");
    cardsv2Log(brandArr);
    jQuery(brandArr).each(function(i,brand) {
        jQuery('#footerBrandsnochexapi').append('<img src="'+nochexapiCardVars.assetsDir + '/img/'+brand+'-3d.svg" style="height:18px;display:inline;margin-left:0.35rem;padding-top:5px;border-radius:unset;" alt="'+brand+'">');
    });
}
function genSlickOneClick(rgArray){
	jQuery(rgArray).each(function(i,rgId) {
		var appendElem = document.querySelector('ul.wc_payment_methods');
		var liElem = document.createElement('li');
		liElem.classList.add('wc_payment_method');
		liElem.classList.add('payment_method_'+ nochexapiCardVars.pluginId);
		var inputElem = document.createElement('input');
		inputElem.setAttribute('id', 'payment_method_' + nochexapiCardVars.pluginId + '_' + rgId['registrationId']);
		inputElem.setAttribute('data-registrationid', rgId['registrationId']);
		inputElem.setAttribute('type', 'radio');
		inputElem.setAttribute('name', 'payment_method');
		inputElem.setAttribute('value', nochexapiCardVars.pluginId);
		inputElem.classList.add('input-radio');
		var labelElem = document.createElement('label');
		labelElem.setAttribute('for', 'payment_method_' + nochexapiCardVars.pluginId + '_' + rgId['registrationId']);
		var textNode = document.createTextNode(rgId['holder'] + ' *** ' + rgId['last4']);
		var imgNode = document.createElement('img');
		imgNode.setAttribute('src', nochexapiCardVars.assetsDir + '/img/' + rgId['paymentBrand'] + '.svg');
		imgNode.setAttribute('alt', rgId['paymentBrand']);
		imgNode.setAttribute('style', 'height:20px;display:inline;border-radius:unset;');
		labelElem.appendChild(textNode);
		labelElem.appendChild(imgNode);
		liElem.appendChild(inputElem);
		liElem.appendChild(labelElem);
		appendElem.appendChild(liElem);
	});
}
function fetchOrdernochexapiCardsModalVersion(endpoint,checkoutData){
    cardsv2Log('start the fetch && modal up');
	cardsv2Log(checkoutData);
	//replace buttons
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
        cardsv2Log('stage 1: POST');
        return response.json();
    }).then(function(json){
		cardsv2Log('stage 2: response json');
		cardsv2Log(json);
        cardsv2Log('stage 3: clear notices stopped');
        //jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        return json.data;
    }).then(function(json){
        cardsv2Log('stage 5: refresh/reload stage if true');
        if(json.refresh === true){
			window.location.reload();
        } else {
			//order pay features
			jQuery("#order_review").unblock();
			document.querySelector('form#order_review').classList.remove('processing');
		}
        return json;
    }).then(function(json){
        cardsv2Log('stage 6: decision logic');
        if(json.result === 'failure'){
			window.location.reload();
        } else {
            return json;
        }
        return false;
    }).then(function(json){
        if(json === false) return false;
        cardsv2Log('stage 7: unload if found');
        if(window.wpwl !== undefined && window.wpwl.unload !== undefined) {
            window.wpwl.unload();
            jQuery('script').each(function () {
                if (this.src.indexOf('static.min.js') !== -1) {
                    jQuery(this).remove();
                }
            });
        }
        return json;
    }).then(function(json){
        if(json === false) return false;
        cardsv2Log('stage 8: oppwa');
        var oppwajs = document.createElement('script');
        oppwajs.setAttribute('src', 'https://' + nochexapiCardVars.platformBase + '/v1/paymentWidgets.js?checkoutId=' + json.checkoutId);
        document.body.appendChild(oppwajs);
        return json;
    }).then(function(json){
        if(json === false) return;
        cardsv2Log('stage 9: swal');
        Swal.fire({
            padding: '0.75rem',
            imageUrl: nochexapiCardVars.assetsDir + '/img/nochexapilogo.png',
            imageHeight: 32,
            html: '<div id="cnpNav"></div><div id="cnpSwal"><form class="paymentWidgets" data-brands="' + nochexapiCardVars.brands + '"></form></div>',
            allowOutsideClick: true,
            allowEscapeKey: true,
            allowEnterKey: false,
            reverseButtons: true,
            showConfirmButton: true,
            showCancelButton: true,
            focusConfirm: true,
            confirmButtonText: 'Process',
            cancelButtonText: 'Cancel',
            footer: '<p><small>Payments powered by Nochex</small></p><div id="footerBrandsnochexapi"></div>',
            customClass: {
                header:'padBottom',
                footer:'flexFootnochexapi'
            },
            didOpen: function(){
                cardsv2Log('didOpen');
				jQuery('.swal2-popup').show();
                displayImgFooternochexapi(nochexapiCardVars.brands);
                Swal.showLoading(Swal.getConfirmButton());
            },
            preConfirm: function(){
                if(jQuery('.wpwl-container-card').is(":visible")){
                    wpwl.executePayment('wpwl-container-card');
                } else if(jQuery('.wpwl-container-registration').is(":visible")){
                    wpwl.executePayment('wpwl-container-registration');
                } else {
                    //cardsv2Log('no containers visible');
                }
                return false;
            }
        }).then(function(result){
            if(result.isDismissed === true) {
                jQuery('#nochexapiCardsBtnReplace').remove();
                jQuery('#place_order').show();
				//order pay features
				jQuery("#order_review").unblock();
				document.querySelector('form#order_review').classList.remove('processing');
                if(window.wpwl !== undefined && window.wpwl.unload !== undefined) {
                    window.wpwl.unload();
                    jQuery('script').each(function () {
                        if (this.src.indexOf('static.min.js') !== -1) {
                            jQuery(this).remove();
                        }
                    });
                }
            }
        });
    });
}
var createRegistrationHtml = '<br style="clear:both;" />';
createRegistrationHtml += '<p id="nochexapiIframeRg" class="form-row form-row-wide" style="float:left!important; text-align:left;">';
createRegistrationHtml += '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
createRegistrationHtml += '<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" id="createRegistration" name="createRegistration" value="true"> <span>Securely store this card?</span>';
createRegistrationHtml += '</label>';
createRegistrationHtml += '</p>';
var wpwlOptions = {
    registrations: { 
        requireCvv: true, 
        hideInitialPaymentForms: false
    },
    iframeStyles: {
        'card-number-placeholder': {
            'color': '#CCC',
            'font-size': '14px',
            'font-family': 'Helvetica'
        },
        'cvv-placeholder': {
            'color': '#CCC',
            'font-size': '14px',
            'font-family': 'Helvetica'
        }
    },
    style:'plain',
    disableSubmitOnEnter:true,
    showLabels:false,
    brandDetection:true,
    brandDetectionType:'regex',
    requireCvv:true,
    showCVVHint:false,
    spinner:{
        color: '#2095ee',
        lines: 18,
        length: 5,
        width: 2,
        radius: 7,
        scale: 3.6,
        corners: 1,
        speed: 2.2
    },
    onReady: function() {
        addRemClassSelector('.wpwl-group-cardNumber',["form-row","form-row-wide"],[]);
        addRemClassSelector('.wpwl-group-expiry',["form-row","form-row-first"],[]);
        addRemClassSelector('.wpwl-group-cvv',["form-row","form-row-last"],[]);
        jQuery('form.wpwl-form-card').find('.wpwl-control-cardNumber').before('<label id="nochexapi-cc-label" class="nochexapiFrameLabel">Card Number&nbsp;<span class="nochexapi-required">*</span></label>');
        jQuery('form.wpwl-form-card').find('.wpwl-control-expiry').before('<label class="nochexapiFrameLabel">Expiry Date&nbsp;<span class="nochexapi-required">*</span></label>');
        jQuery('form.wpwl-form-card').find('.wpwl-control-cvv').before('<label class="nochexapiFrameLabel">Card Code (CVC)&nbsp;<span class="nochexapi-required">*</span></label>');
        if(parseInt(nochexapiCardVars.createRegistration) === 1 && parseInt(nochexapiCardVars.loggedIn) === 1){
            jQuery('form.wpwl-form-card').find('.wpwl-group-cvv').after(createRegistrationHtml);
        }
        checkWpwlContainers();
        Swal.hideLoading();
        cardsv2Log('wpwlReady');
    },
    onReadyIframeCommunication: function(){
        var iframeField = this.$iframe[0];
        if(iframeField.parentNode.classList.contains('wpwl-registration') === false){
            if(iframeField.classList.contains('wpwl-control-cardNumber') === true){
                var ccNoContainer = iframeField.parentNode;
                var brandContainer = document.createElement('div');
                brandContainer.id= "wpwlDynBrand";
                var dynBrandImg = document.createElement('img');
                dynBrandImg.id = "wpwlDynBrandImg";
                dynBrandImg.src = nochexapiCardVars.assetsDir + '/img/' + 'default.svg';
                brandContainer.appendChild(dynBrandImg);
                ccNoContainer.appendChild(brandContainer);
            }
        }
    },
    onDetectBrand: function(brands){
        var dynBrandImgSrc;
        if(brands.length > 0){
            dynBrandImgSrc = nochexapiCardVars.assetsDir + '/img/' + brands[0] + '.svg';
        } else {
            dynBrandImgSrc = nochexapiCardVars.assetsDir + '/img/' + 'default.svg';
        }
        document.getElementById('wpwlDynBrandImg').src = dynBrandImgSrc;
    },
    onAfterSubmit: function(){
        cardsv2Log('onAfterSubmit');
        jQuery('#cnpNav').empty();
        Swal.showLoading(Swal.getConfirmButton());
        return true;
    },
    onLoadThreeDIframe: function(){
        cardsv2Log('onLoadThreeDIframe');
        Swal.hideLoading();
        jQuery(Swal.getConfirmButton()).hide();
    },
    onError: function(error){
        cardsv2Log(error);
		//window.location.reload();
    }
};
var nochexapiCardsHandoff = function() {
    if(jQuery('form#order_review').find('input[name^="payment_method"]:checked').val() === nochexapiCardVars.pluginId){
        var checkoutData = jQuery("form#order_review").serialize();
		if(nochexapiCardVars.slickOneClick === '1'){
			if( jQuery('form#order_review').find('input[name^="payment_method"]:checked').data("registrationid") !== undefined ) {
				checkoutData += '&registrationId=' + jQuery('form#order_review').find('input[name^="payment_method"]:checked').data("registrationid");
			}
		}
    	document.querySelector('form#order_review').classList.add('processing');
		cardsv2Log('nochexapiCards');
		checkoutData += '&action=nochexapiOrderPay';
    	fetchOrdernochexapiCardsModalVersion(nochexapiCardVars.adminUrl,checkoutData);
		return false;
    }
	return;
};
jQuery(function(){
    console.log(nochexapiCardVars.pluginId + ' v.' + nochexapiCardVars.pluginVer);
    cardsv2Log(nochexapiCardVars);
    var checkout_form = jQuery( 'form#order_review' );
    checkout_form.on( 'submit', nochexapiCardsHandoff );
    jQuery('body').on('click','.cnpTarget', function(e) {
        e.preventDefault();
        var targetContainer = jQuery(this).data('target');
        jQuery('.wpwl-container').each(function() {
            if(jQuery(this).hasClass(targetContainer)){
                jQuery(this).show();
            } else {
                jQuery(this).hide();
            }
        });
    });
	if(jQuery('ul.woocommerce-error').length){
		cardsv2Log('errors present!!');
		var nochexapiNotices = document.querySelector("ul.woocommerce-error");
		nochexapiNotices.scrollIntoView();
	}
});