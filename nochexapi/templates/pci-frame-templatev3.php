<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
if(isset($_GET['resourcePath'])){ ?>
<!DOCTYPE html> 
<html <?php language_attributes(); ?>>
<head>
    <meta name="robots" content="noindex,nofollow">
    <meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="http://gmpg.org/xfn/11">
	<meta http-equiv="X-UA-Compatible" content="IE=edge"> 
	<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Transaction confirmation</title>
</head>
<body>
<h3>Please wait while payment is processing. Do not refresh or close it.</h3>
<script>
    var messageObj = {
        channel: "resourcePath",
        data: "<?php echo $_GET['resourcePath']; ?>"
    };
    document.addEventListener("DOMContentLoaded", function(){
        console.log('resourcePath hit now ->> window.parent.postMessage');
        console.log(messageObj);
        window.parent.postMessage(JSON.stringify(messageObj), "<?php echo ( is_ssl() ? 'https' : 'http' );?>://<?php echo $_SERVER['HTTP_HOST']; ?>");
    });
</script>
</body>
</html>
<?php } else { ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta name="robots" content="noindex,nofollow">
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="http://gmpg.org/xfn/11">
	<meta http-equiv="X-UA-Compatible" content="IE=edge"> 
	<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Transaction processing</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <style id="tprootcss" type="text/css">:root {--main-primary-color: #2372ce;--main-accent-color: #7f54b3; --wpwl-control-background: transparent!important;; --wpwl-control-border-radius: 0px!important;--wpwl-control-margin-right:12px;}</style>
    <style type="text/css">	
    html, body, fieldset {margin:0!important; padding:0!important; background: var(--main-primary-color); font-family:'Helvetica Neue',Helvetica,Arial,sans-serif; color: #000;}
    #wpwlDynBrand {width:30px; padding:11px; position: absolute; right: 0px; top: 0px;}
    #wpwlDynBrandImg {display: none; border-radius: unset; height: -webkit-fill-available; margin:0!important; float: right; max-height: 12px;}
    .form-row-first{float:left; width:45%!important;}
    .form-row-last{float:right; width:45%!important;}
    .form-row-wide{margin-bottom: 1.75rem; width:100%!important;}
    .form-row input::placeholder{color: #000; font-size: 16px; font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;}
    .wpwl-control-expiry {/*height: 2.125em;*/ font-size: 16px;height: 34px!important; border-radius:0px; box-shadow: none;-webkit-appearance: none; -moz-appearance: none; appearance: none;}
    .wpwl-group-brand, .wpwl-group-cardHolder, .wpwl-group-submit {display:none;}
	.wpwl-wrapper-registration-holder {display:none;}
	.wpwl-wrapper-registration-brand .wpwl-brand-inline {width:34px; height:34px; background:none;}
    .wpwl-wrapper-cardNumber, .wpwl-wrapper-expiry, .wpwl-wrapper-cvv {position:unset!important;}
    .wpwl-control-cardNumber {font-size: 16px; height: 34px; line-height: 34px;}
    .frameContainer {margin-top:2px; padding: 1rem 1.4rem 0;}
    .wpwl-form {max-width: 480px!important; margin-bottom:0!important;}
    div.wpwl-hint {font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif; font-size:x-small;color:var(--wpwl-control-font-color)!important;padding: 0 0 7px 7px!important;}
    input.wpwl-control-expiry:-webkit-input-placeholder {font-size: 16px;color:#000!important; font-size: 14px!important;}
    input.wpwl-control-expiry:-ms-input-placeholder {font-size: 16px;color:#000!important; font-size: 14px!important;}
    input.wpwl-control-expiry::placeholder {font-size: 16px;color:#000!important;}
    label.tpFrameLabel {color:#333; font-size: 11px; display: block; text-align: left; line-height: 140%; margin-bottom: 3px; max-width: 100%; font-family: 'Helvetica Neue',Helvetica,Arial,sans-serif;}
    span.tp-required {color:#FF0000;}
	.wpwl-form-has-inputs {
        padding: 0px!important;
        border: none!important;
        background-color: none!important;
        border-radius: 0px!important;
        -webkit-box-shadow: none!important;
        box-shadow: none!important;
	}
    #tpIframeRg {display: flex; align-items: center; margin:0; padding:0; border:none;}
    #tpIframeRg span {color:#000!important; font-size: smaller;}
    .wpwl-group-registration {
        margin: 0;
        padding: 0;
        border: none;
    }
    .wpwl-wrapper-registration{
	float:none!important;
    }

    .wpwl-wrapper-registration-brand{width: 20%!important;}
    
    label.wpwl-registration {
        display: flex;
        align-items: center;
    }
    .wpwl-wrapper-registration-registrationId {
        width: 0;
        display: none;
    }
    .wpwl-wrapper-registration-number, .wpwl-wrapper-registration-expiry {
        padding-right: 10px;
    }
    .wpwl-wrapper-registration-details {
        margin-bottom: 0;
        font-size: small;
        display: flex;
        align-items: center;
        width: 70%;
    }
	#nochexapi_iframe_container #nochexapi_cnpFrame{
	height:200px!important;
	}
    div.wpwl-wrapper-registration-cvv {
        width: 32%;
    }
    .wpwl-group-registration.wpwl-selected {
		margin: 0;
        padding: 0;
        border: none;
	}
    .wpwl-group-registration.wpwl-selected div.wpwl-wrapper-registration-details {
		color: #000;
	}
    div.wpwl-group {width:unset;background:var(--wpwl-control-background);color: #000;border-radius:var(--wpwl-control-border-radius)!important;margin-right:var(--wpwl-control-margin-right)!important;border: var(--wpwl-control-border-width) solid var(--wpwl-control-border-color)!important;}
    div.wpwl-group.wpwl-group-registration {width:unset;background:var(--wpwl-primary-background);color: #000;}

    .wpwl-control {
        border:none!important;
        background-color:#fff!important;
        color:#000!important;
    }
    div.wpwl-group-cardNumber {
	background-color:#fff!important;
	color:#000!important;
    }
    div.wpwl-group-expiry {
	background-color:#fff!important;
	color:#000!important;
    }
    div.wpwl-group-cvv {
	background-color:#fff!important;
	color:#000!important;
    }
    button#tpcards-container-change {
	/*display: block;*/
        width: 68%;
        max-width: 480px;
        border-width: 1px;
        border-style: solid;
        border-color: #000;
        color: #000;
        background-color: #fff;
        padding: 8px;
        font-size: small;
        font-weight: lighter;
        margin-bottom: 1rem;
    }
        div.wpwl-group-cardNumber {
            width:75%;
        }
        div.wpwl-group-expiry {
            width:30%;
        }
        div.wpwl-group-cvv {
            width:30%;
        }
    @media (max-width: 600px) {
        .wpwl-control-cardNumber {font-size: inherit;}
        div.wpwl-group-cardNumber {
            width:100%!important;
        }
        div.wpwl-group-expiry {
            width:100%!important;
        }
        div.wpwl-group-cvv {
            width:100%!important;
        }

    }
    .wpwl-control.wpwl-control-iframe.wpwl-control {
        padding-bottom: 0 !important;
        padding-top: 0 !important;
    }
    ::placeholder{
        color: #000!important;
    }
    ::-webkit-input-placeholder{
        color: #000!important;
    }
    :-ms-input-placeholder{
        color: #000!important;
    }
    </style>
</head>
<body id="frameBody">
<div id="cnpf" class="frameContainer"></div>
<div id="mcif" class="frameContainer" style="display:none;">
    <iframe id="myCustomIframe" name="myCustomIframe" style="border:none; width:100%; height:600px;"></iframe>         
</div>
<script>
var paymentContainer = 'wpwl-container-card';
var cssConfigs       = {};
function getInternetExplorerVersion()
{
  var rv = -1;
  if (navigator.appName == 'Microsoft Internet Explorer')
  {
    var ua = navigator.userAgent;
    var re  = new RegExp("MSIE ([0-9]{1,}[\.0-9]{0,})");
    if (re.exec(ua) != null)
      rv = parseFloat( RegExp.$1 );
  }
  else if (navigator.appName == 'Netscape')
  {
    var ua = navigator.userAgent;
    var re  = new RegExp("Trident/.*rv:([0-9]{1,}[\.0-9]{0,})");
    if (re.exec(ua) != null)
      rv = parseFloat( RegExp.$1 );
  }
  return rv;
}


const Observe = (sel, opt, cb) => {
  const Obs = new MutationObserver((m) => [...m].forEach(cb));
  document.querySelectorAll(sel).forEach(el => Obs.observe(el, opt));
};

// DEMO TIME:
Observe("#mcif", {
  attributesList: ["style"], 
  attributeOldValue: true,
}, (m) => {
  
		jQuery("#nochexapi_cnpFrame", window.parent.document).height(650);
  
});
 
	 
	 
//!!iFrame communication functions!!
function postMessageToParent(obj){
	//
	const selectElement = document.getElementById('mcif');

	selectElement.addEventListener('onChange', (event) => {
		alert("test");
		chgNcxHgt(650);
	});
	
	
   if(typeof window.CustomEvent === "function") {
        var event = new CustomEvent('parentLogV52', {detail:obj});
    } else {
        var event = document.createEvent('Event');
        event.initEvent('parentLogV52', true, true);
        event.detail = obj;
    }
    window.parent.document.dispatchEvent(event);
	
	
}
	
	function chgNcxHgt (chH) {
		jQuery("#nochexapi_cnpFrame", window.parent.document).height(chH);
	}	
function parentWindowComms(e){
    if(e.detail.hasOwnProperty('funcs')){
        for (var i = 0, len = e.detail.funcs.length; i < len; i++) {
            if(typeof window[e.detail.funcs[i].name] === 'function'){
                window[e.detail.funcs[i].name](e.detail.funcs[i].args);
            }
        }
    }
}
//!!end iFrame communication functions!!
function childFrameInit(){
    postMessageToParent({funcs:[{"name":"sendnochexapiVarsObject","args":[true]}]});
}
function drawFormElementToPage(brands){
    console.log('drawing form.paymentWidgets element! =>' + brands);
	chgNcxHgt(220);
    jQuery('#cnpf').html('<div id="tpcards-form-controller" style="display:none;text-align:center;"><button id="tpcards-container-change" onclick="chgNcxHgt(350)">Use new card</button></div><form class="paymentWidgets" data-brands="' + brands + '"></form>');
}
function drawOppwaScriptToPage(platform_base,checkout_id){
    console.log('Loading: https://' + platform_base + '/v1/paymentWidgets.js?checkoutId=' + checkout_id + ' =>');
    var scriptElement = document.createElement( "script" );
 	scriptElement.onload = function() {
		console.log('Successfully loaded https://' + platform_base + '/v1/paymentWidgets.js?checkoutId=' + checkout_id + ' using (onload).');
	};
 	scriptElement.src = "https://" + platform_base + "/v1/paymentWidgets.js?checkoutId=" + checkout_id;
	document.body.appendChild( scriptElement );
}
function drawCssBlockToPage(cssText){
    //console.log('drawing css block to head');
    var tprootcss = document.getElementById("tprootcss");
    //console.log(tprootcss);
    tprootcss.innerHTML = cssText;
    //console.log('completed');
}
function genElemHight(delayms){
    setTimeout(function(){
        //console.log('running genElemHight('+delayms+') =>');
        var elmnt = document.getElementById("frameBody");
        var height = (elmnt.offsetHeight + 16);
        postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[height]}]});
    }, delayms);
}
function postNewFrameHeight(enforceHeight){
	enforceHeight = false;
    if(enforceHeight === false){
        var frameContainer = document.querySelector('#frameBody');
        var ie  = getInternetExplorerVersion();
        var frameHeight   = ie == -1 || ie >= 11 ? frameContainer.offsetHeight : frameContainer.scrollHeight;
        console.log('calc new: ' + frameHeight);
        frameHeight = Math.round(frameHeight);
        if(frameHeight < 180){
            frameHeight = 250;
        } else {
            frameHeight = frameHeight + 28;
        }
        postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[frameHeight]}]});
    } else {
        postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[enforceHeight]}]});
    }
}
function initialiseCnp(args){
    console.log('initialiseCnp =>');
    console.log(args);
    if(typeof args === 'object'){
        if(args.length === 2){
			parentArgs = args[0];
			if(args[0]['autoFocusFrameCcNo'] === "1"){
				wpwlOptions.autofocus = 'card.number';
			}
            if(args[0]['createRegistration'] !== "1" || args[0]['loggedIn'] !== "1"){
                createRegistrationHtml = '';
            }
            cssConfigs = {'mainbgcolor': args[0]['frameCss']['framePrimaryColor'], 'fontcolor': args[0]['frameCss']['framewpwlControlFontColor']};
            var rootCss = ':root {--main-primary-color: '+args[0]['frameCss']['framePrimaryColor']+'; --main-accent-color: '+args[0]['frameCss']['frameAccentColor']+';--wpwl-control-background:'+args[0]['frameCss']['framewpwlControlBackground']+';--wpwl-control-font-color:'+args[0]['frameCss']['framewpwlControlFontColor']+';--wpwl-control-border-radius:'+args[0]['frameCss']['framewpwlControlBorderRadius']+'px;--wpwl-control-border-color:'+args[0]['frameCss']['framewpwlControlBorderColor']+';--wpwl-control-border-width:'+args[0]['frameCss']['framewpwlControlBorderWidth']+'px;--wpwl-control-margin-right:'+args[0]['frameCss']['framewpwlControlMarginRight']+'px!important;}';
            drawCssBlockToPage(rootCss);
            drawFormElementToPage(args[0]['brands']);
            drawOppwaScriptToPage(args[0]['platformBase'],args[1]);
        }
    }
}
function switchContainer(selectedContainer){
    var nodeList = document.querySelectorAll(".wpwl-container");
    for (var i = 0, len = nodeList.length; i < len; i++) {
        if(nodeList[i].classList.contains(selectedContainer) === true){
            nodeList[i].style.display = "block";
            console.log('switchContainerCalc');
            postNewFrameHeight(false);
        } else {
            nodeList[i].style.display = "none";
            console.log('switchContainerCalc');
            postNewFrameHeight(false);
        }
    }
    paymentContainer = selectedContainer;
}
function adjRgState(argArray){
    var rgText = document.getElementById('tpIframeRg');
    var rgCheckbox = document.getElementById('createRegistration');
    if(typeof(rgText) !== 'undefined' && rgText !== null){
        if(typeof(rgCheckbox) !== 'undefined' && rgCheckbox !== null){
            if(argArray[0] === true){
                rgText.style.display = "block";
                rgCheckbox.disabled = false;
                console.log('adjRgStateCalc');
                postNewFrameHeight(false);
            } else {
                rgText.style.display = "none";
                rgCheckbox.disabled = true;
                console.log('adjRgStateCalc');
                postNewFrameHeight(false);
            }
        }
    }
}
function addRemClassSelector(elemSelector,classAdd,classRemove){
    var elem = document.querySelector(elemSelector);
    for (var i = 0, len = classAdd.length; i < len; i++) {
        elem.classList.add(classAdd[i]);
    }
}
function genHeightCalculations(){
	if(parentArgs.createRegistration === '1'){
		containerCardsHeight += 25;
		if(jQuery('form.wpwl-form-registrations').length > 0){
			containerCardsHeight += 50;
			containerRgsHeight += (jQuery('.wpwl-group-registration').length * 50);
		}
	}
	if(jQuery('.wpwl-group-registration').length){
		postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[containerRgsHeight]}]});
	} else {
		postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[containerCardsHeight]}]});
	}
}
//wpwl functions
function executePayment(argArray){
    wpwl.executePayment(paymentContainer);
}
function addInlineImgPerBrand(brand){
	//console.log('adding brand image for: ' + brand);
	var selectorString = 'div.wpwl-brand.wpwl-brand-'+brand+'.wpwl-brand-inline';
	var elems = jQuery(selectorString);
	if(elems.length){
		jQuery.each( elems, function(i,c) {
			if(jQuery(c).children().length === 0) {
				jQuery(c).html('<img src="<?php echo plugin_dir_url( dirname( __FILE__ ) ).'assets/img/'; ?>' + brand + '-inline.svg" width="34" height="34">');
			}
		});
	}
}
//init dynamics
var parentArgs = {};
//init statics
var containerCardsHeight = 150;
var containerRgsHeight = 150;
var rgBranding = true;
var createRegistrationHtml = `<br style="clear:both;" /><p id="tpIframeRg">
<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="createRegistration" name="createRegistration" value="true"><span>&nbsp;&nbsp;Securely store this card?</span>
</p><br style="clear:both;" />`;
var wpwlOptions = {
    registrations: {
        requireCvv: true,
        hideInitialPaymentForms: false
    },
    iframeStyles: {
        'card-number-placeholder': {
            'color': '#000',
            'font-size': '16px',
            'font-family': 'Helvetica'
        },
        'cvv-placeholder': {
            'color': '#000',
            'font-size': '16px',
            'font-family': 'Helvetica'
        }
    },
    labels: {
        cardNumber: 'Card Number',
        mmyy: 'MMYY',
        cvv: 'CVV'
    },
    errorMessages: {
        cardNumberError: 'Invalid card number',
        expiryMonthError: 'Invalid exp.',
        expiryYearError: 'Invalid exp.',
        cvvError: 'Invalid cvv'
    },
    style: 'plain',
    paymentTarget: 'myCustomIframe',
    shopperResultTarget: 'myCustomIframe',
    disableSubmitOnEnter: true,
    showLabels: false,
    brandDetection: true,
    brandDetectionType: 'binlist',
    requireCvv: true,
    showCVVHint: false,
    onReady: function() {
		genHeightCalculations();
        jQuery('form.wpwl-form-card').find('.wpwl-group-cvv').after(createRegistrationHtml);
        jQuery('#tpcards-container-change').on('click', function(e) {
            e.preventDefault();
            //console.log('change container ....');
            if(jQuery('form.wpwl-form-card').is(":visible")){
                jQuery('form.wpwl-form-card').hide();
                jQuery('form.wpwl-form-registrations').show();
                jQuery('#tpcards-container-change').text('Payment with new card');
                paymentContainer = 'wpwl-container-registration';
				postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[containerRgsHeight]}]});
            } else {
                jQuery('form.wpwl-form-registrations').hide();
                jQuery('form.wpwl-form-card').show();
                jQuery('#tpcards-container-change').text('Payment with stored card(s)');
                paymentContainer = 'wpwl-container-card';
				postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[containerCardsHeight]}]});
            }
        });
        console.log('wpwl onReady');
       /* window.wpwl.checkout.config.customRedirectPageConfig.backgroundColor = cssConfigs.mainbgcolor;
        window.wpwl.checkout.config.customRedirectPageConfig.hyperlinkFontColor = cssConfigs.fontcolor;
        window.wpwl.checkout.config.customRedirectPageConfig.messageFontColor = cssConfigs.fontcolor;*/
        //console.log(window.wpwl.checkout.config.customRedirectPageConfig);
    },
    onReadyIframeCommunication: function(){
        console.log('wpwl iframe communication started');
        var iframeField = this.$iframe[0];
        if(iframeField.parentNode.classList.contains('wpwl-wrapper-registration') === true){
            jQuery('form.wpwl-form-card').hide();
            jQuery('#tpcards-form-controller').show();
            paymentContainer = 'wpwl-container-registration';
			if(rgBranding === true){
				//console.log('branding req.');
				var brandsArray = (parentArgs.brands).split(" ");
				jQuery.each( brandsArray, function(i,b) {
					addInlineImgPerBrand(b);
				});
				rgBranding = false;
			}
        }
        if(iframeField.classList.contains('wpwl-control-cardNumber') === true){
            var ccNoContainer = iframeField.parentNode;
            var brandContainer = document.createElement('div');
            brandContainer.id="wpwlDynBrand";
            var dynBrandImg = document.createElement('img');
            dynBrandImg.id="wpwlDynBrandImg";
            dynBrandImg.src='<?php echo plugin_dir_url( dirname( __FILE__ ) ).'assets/img/'; ?>' + 'default.svg';
            brandContainer.appendChild(dynBrandImg);
            ccNoContainer.appendChild(brandContainer);
        }
        console.log('wpwl iframe communication ended');
    },
    onFocusIframeCommunication: function(){
        //var parentEl = this.$iframe[0].parentNode;
        genElemHight(750);
    },
    onBlurIframeCommunication: function(){
        //var parentEl = this.$iframe[0].parentNode;
    },
    onBlurCardNumber: function(isValid){ 
        //console.log(isValid);
    },
    onDetectBrand: function(brands){
        var dynBrandImgSrc;
        if(brands.length > 0){
            dynBrandImgSrc = '<?php echo plugin_dir_url( dirname( __FILE__ ) ).'assets/img/'; ?>' + brands[0] + '.svg';
        } else {
            dynBrandImgSrc = '<?php echo plugin_dir_url( dirname( __FILE__ ) ).'assets/img/'; ?>' + 'default.svg';
        }
        document.getElementById('wpwlDynBrandImg').src = dynBrandImgSrc;
    },
    onBeforeSubmitCard: function(event){
        console.log('onBeforeSubmitCard');
        //postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[containerCardsHeight]}]});
        postMessageToParent( { funcs: [ { "name": "tpSetFrameHeight", "args": [300] } ] } );
        return true;
    },
    onAfterSubmit: function(){
        //document.getElementById('payment').style.display = "none";
        console.log('onAfterSubmit');
        postMessageToParent({funcs:[{"name":"progress_tp_cardsv2","args":[true]}]});
        if(Object(window.parent.document.getElementById('tp_cardsv2_checkout_id')) === window.parent.document.getElementById('tp_cardsv2_checkout_id')){
            console.log('clearing checkout_id');
            window.parent.document.getElementById('tp_cardsv2_checkout_id').value = "";
        }
        jQuery('#cnpf').hide();
        jQuery('#mcif').show();
        postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[580]}]});
        return true;
    },
    onLoadThreeDIframe: function(){
        console.log('onLoadThreeDIframe');
        postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[580]}]});
    },
    onError: function(error){
        console.log('error::'+error);
        if (error.name === "InvalidCheckoutIdError") {
            console.log('refresh child frame');
            childFrameInit();
        } else if (error.name === "WidgetError") {
            console.log("here we have extra properties: ");
            console.log(error.brand + " and " + error.event);
            childFrameInit();
        }
        // read the error message
		//postMessageToParent({funcs:[{"name":"tpUnSetParentBlockUI","args":[300]}]});
		postMessageToParent({funcs:[{"name":"tpSetFrameHeight","args":[300]}]});
    }
};
//init event listeners
window.document.addEventListener('frameLogV52', parentWindowComms, false);
window.addEventListener('message', function(e) {
    var decoded = false;
    if(e.origin === "<?php echo ( is_ssl() ? 'https' : 'http' );?>://<?php echo $_SERVER['HTTP_HOST']; ?>"){
        try {
            decoded = JSON.parse(e.data);
        } catch(e) {
            decoded = false;
        }
        if(decoded !== false){
            if(decoded.hasOwnProperty("channel")){
                if(decoded.channel === 'resourcePath'){
                    if(decoded.hasOwnProperty("data")){
                        //console.log(decoded.data);
                        postMessageToParent({funcs:[{"name":"validate_nochexapi_cardsv2_checkout","args":[decoded.data]}]});
                    }
                }
            }
        }
    }
});
jQuery(document).ready(function() {
    console.log('child frame doc.ready');
    //console.log('domain: ' + '<?php echo ( is_ssl() ? 'https' : 'http' );?>://<?php echo $_SERVER['HTTP_HOST']; ?>');
    childFrameInit();
});
</script>
</body>
</html>
<?php } ?>
