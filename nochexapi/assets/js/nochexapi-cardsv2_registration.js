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
function displayImgFooternochexapi(brands){
    var brandArr = brands.split(" ");
    cardsv2Log(brandArr);
    jQuery(brandArr).each(function(i,brand) {
        jQuery('#footerBrandsnochexapi').append('<img src="'+nochexapiCardVars.assetsDir + '/img/'+brand+'-3d.svg" style="height:18px;display:inline;margin-left:0.35rem;padding-top:5px;border-radius:unset;" alt="'+brand+'">');
    });
}
function validateHolder(){
	cardsv2Log('vholder');
  	var holder = jQuery('.wpwl-control-cardHolder').val();
	if (holder.trim().length < 2){
		if(!jQuery('.wpwl-control-cardHolder').hasClass('wpwl-has-error')){
			jQuery('.wpwl-control-cardHolder').addClass('wpwl-has-error').after('<div class="wpwl-hint wpwl-hint-cardHolderError">Invalid card holder</div>');
		}
		return false;
	}
	return true;
}
var wpwlOptions = {
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
	useSummaryPage:true,
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
		jQuery('.wpwl-group-cardHolder').show();
		addRemClassSelector('.wpwl-group-cardHolder',["form-row","form-row-wide"],[]);
        addRemClassSelector('.wpwl-group-cardNumber',["form-row","form-row-wide"],[]);
        addRemClassSelector('.wpwl-group-expiry',["form-row","form-row-first"],[]);
        addRemClassSelector('.wpwl-group-cvv',["form-row","form-row-last"],[]);
		jQuery('form.wpwl-form-card').find('.wpwl-control-cardHolder').before('<label id="nochexapi-cc-label" class="nochexapiFrameLabel">Card Holder Name&nbsp;<span class="nochexapi-required">*</span></label>');
        jQuery('form.wpwl-form-card').find('.wpwl-control-cardNumber').before('<label id="nochexapi-cc-label" class="nochexapiFrameLabel">Card Number&nbsp;<span class="nochexapi-required">*</span></label>');
        jQuery('form.wpwl-form-card').find('.wpwl-control-expiry').before('<label class="nochexapiFrameLabel">Expiry Date&nbsp;<span class="nochexapi-required">*</span></label>');
        jQuery('form.wpwl-form-card').find('.wpwl-control-cvv').before('<label class="nochexapiFrameLabel">Card Code (CVC)&nbsp;<span class="nochexapi-required">*</span></label>');
		jQuery('.wpwl-group-cardHolder').before(jQuery('.wpwl-group-cvv'));
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
	onBeforeSubmitCard: function(){
    	return validateHolder();
  	},
	onSaveTransactionData: function(data){
		cardsv2Log('onSaveTransactionData');
		Swal.showLoading(Swal.getConfirmButton());
		var wpwlForm = jQuery('form.wpwl-form-card');
		var actionUrl = wpwlForm[0].action;
		if(typeof actionUrl === 'string'){
			fetch(nochexapiCardVars.adminUrl , {
				method:'post',
				headers: {
					'Accept': 'application/json',
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				cache: 'no-cache',
				body: 'action=parseRgCheckout&actionUrl='+actionUrl
			}).then(function (response){
				return response.json();
			}).then(function (json){
				cardsv2Log(json);
				Swal.hideLoading();
				return json;
			}).then(function (json){
				Swal.fire({
					padding: '0.75rem',
					icon: json.data.swalType,
					title: 'New Payment Method',
					html: '<p>'+json.data.message+'</p>',
					allowOutsideClick: true,
					allowEscapeKey: true,
					showConfirmButton: true,
					showCancelButton: false,
					focusConfirm: true,
					confirmButtonText: 'Ok',
					footer: '<p><small>Payments powered by Total Processing</small></p><div id="footerBrandsnochexapi"></div>',
					customClass: {
						header:'padBottom',
						footer:'flexFootnochexapi'
					},
					didOpen: function(){
						cardsv2Log('didOpen');
						jQuery('.swal2-popup').show();
						displayImgFooternochexapi(nochexapiCardVars.brands);
					},
					didDestroy: function(){
						cardsv2Log('didDestroy');
						if(json.data.hasOwnProperty('redirect')){
							cardsv2Log(json.data.redirect);
							window.location = (json.data.redirect);
						}
					}
				});
			});
		}
	},
    onError: function(error){
        cardsv2Log(error);
    }
};
function genRgFlow(){
	fetch(nochexapiCardVars.adminUrl , {
        method:'post',
		headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        cache: 'no-cache',
        body: 'action=requestRgCheckoutId'
	}).then(function (response){
		if(window.wpwl !== undefined && window.wpwl.unload !== undefined) {
        	window.wpwl.unload();
			jQuery('script').each(function () {
				if (this.src.indexOf('static.min.js') !== -1) {
					jQuery(this).remove();
				}
			});
		}
		return response.json();
	}).then(function (json){
		if(json.success){
			return json.data;
		}
		return false;
	}).then(function (checkoutId){
		if(checkoutId === false) return false;
		cardsv2Log(checkoutId);
		var oppwajs = document.createElement('script');
        oppwajs.setAttribute('src', 'https://' + nochexapiCardVars.platformBase + '/v1/paymentWidgets.js?checkoutId=' + checkoutId);
        document.body.appendChild(oppwajs);
        return true;
	}).then(function(res){
		if(res){
			execSwalRg();
		}
	});
};
function execSwalRg(){
		Swal.fire({
            padding: '0.75rem',
            imageUrl: nochexapiCardVars.assetsDir + '/img/nochexapilogo.png',
            imageHeight: 32,
            html: '<div id="cnpSwal"><form action="' + nochexapiCardVars.shopperUrl + '" class="paymentWidgets" data-brands="' + nochexapiCardVars.brands + '"></form></div>',
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
				validateHolder();
                wpwl.executePayment('wpwl-container-card');
                return false;
            },
			didDestroy: function(){
				cardsv2Log('didDestroy');
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
}
jQuery(function(){
    console.log(nochexapiCardVars.pluginId + ' v.' + nochexapiCardVars.pluginVer);
    cardsv2Log(nochexapiCardVars);
	jQuery('body').on('submit','form#add_payment_method', function(e) {
		if(jQuery('form#add_payment_method').find('input[name^="payment_method"]:checked').val() === nochexapiCardVars.pluginId){
        	jQuery("#add_payment_method").unblock();
			genRgFlow();
			return false;
    	}
		return;
    });
});