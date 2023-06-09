<?php
use Nochexapi\WC_Nochexapi_Constants AS Nochexapi_CONSTANTS; 
return array(
    'general' => array(
        'title' => 'Nochex API',
        'description'=>'<p>General settings</p>',
        'fields'=> array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'label' => 'Enable Card Payments',
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
                'class' => 'wppd-ui-toggle',
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'class' => '',
                'description' => 'This controls title text during checkout.',
                'default' => 'Pay with card',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'text',
                'class' => '',
                'description' => 'This controls the description seen at checkout1.',
                'default' => 'Checkout with Card',
                'desc_tip' => true
            ),
            'platformBase' => array(
                'title' => 'Current Endpoint mode',
                'type' => 'select',
                'class' => 'wc-enhanced-select-nostd',
                'default' => 'oppwa.com',
                'options' => array(
                    'oppwa.com' => 'Live',
                    'test.oppwa.com' => 'Test'
                )
            ),
            /*'entityId_test' => array(
                'title' => 'Entity Id <b style="color: orange">(TEST)</b>',
                'type' => 'text',
                'class' => '',
                'description' => 'Enabled channel for test card payments.',
                'default' => '8ac7a4ca7843f17d017844faa85f0829',
                'desc_tip' => true,
            ),*/
            /*'accessToken_test' => array(
                'title' => 'Access Token <b style="color: orange">(TEST)</b>',
                'type' => 'text',
                'class' => '',
                'description' => 'Enabled token for test card payments',
                'default' => 'OGFjN2E0Y2E3ODQzZjE3ZDAxNzg0NGY4MTFjNjA4MjR8V2hFMlB4WHdFcA',
                'desc_tip' => true,
            ),*/
            'entityId' => array(
                'title' => 'Entity Id <b style="color: green">(LIVE)</b>',
                'type' => 'text',
                'class' => '',
                'description' => 'Enabled channel for live card payments. Provided by Nochex.',
                'default' => '',
                'desc_tip' => true,
            ),
            'accessToken' => array(
                'title' => 'Access Token <b style="color: green">(LIVE)</b>',
                'type' => 'text',
                'class' => '',
                'description' => 'Enabled token for live card payments. Provided by Nochex.',
                'default' => '',
                'desc_tip' => true,
            ),
            /*'paymentType' => array(
                'title' => 'Authorisation Type',
                'type' => 'select',
                'class' => 'wc-enhanced-select-nostd',
                'description' => 'Reccomend using DB as (default) direct capture, some aquirers are not compatible with PA.',
                'default' => "DB",
                'desc_tip' => true,
                'options' => array(
                    "DB" => "Debit (DB) transaction immediately captures payment"
                )
            ),*/
            /*'createRegistration' => array(
                'title' => 'Enable tokenisation? <small><em>Default:Off</em></small>',
                'label' => 'Allow stored payment methods',
                'type' => 'checkbox',
                'class' => 'wppd-ui-toggle',
                'description' => 'Create secure payment token from initial payment or allow new payment method creation.',
                'default' => 'no',
                'desc_tip' => true,
            ),*/
            /*'includeCartData' => array(
                'title' => 'Enable cart/order items data? <small><em>Default:Off</em></small>',
                'label' => 'Cart items data?',
                'type' => 'checkbox',
                'class' => 'wppd-ui-toggle',
                'description' => 'Embed cart item data in transaction data?',
                'default' => 'no',
                'desc_tip' => true,
            ),*/
/*            'checkoutOrderCleanup' => array(
                'title' => 'Enable cancelling replaced orders? <small><em>Default:Off</em></small>',
                'label' => 'Cancel prior orders?',
                'type' => 'checkbox',
                'class' => 'wppd-ui-toggle',
                'description' => '',
                'default' => 'no',
                'desc_tip' => false,
            ),*/	   
            /*'paymentBrands' => array(
                'title' => 'Card schemes enabled',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select-nostd',
                'default' => array(
                    'VISA',
                    'MASTER'
                ),
                'options' => array(
                    'VISA'=> 'Visa',
                    'MASTER' => 'Mastercard'
                )
            ),*/
            /*'threeDv2' => array(
                'title' => '3D Secure 2.x data',
                'label' => 'Send additional 3d Secure version 2 data <small><em>Default:On</em></small>',
                'type' => 'checkbox',
                'class' => 'wppd-ui-toggle',
                'description' => 'Use 3d Secure version 2.x field parameters',
                'default' => 'yes',
                'desc_tip' => true,
            ),*/
            /*'threeDv2Params' => array(
                'title' => '3Dv2 params enabled',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select-nostd',
                'default' => array(
                    'ReqAuthMethod'
                ),
                'options' => array(
                    'ReqAuthMethod' => 'ReqAuthMethod'
                ),
            ),*/
            /*'transactionType3d' => array(
                'title' => 'Nature of payments for 3dv2 data',
                'type' => 'select',
                'class' => 'wc-enhanced-select-nostd',
                'description' => 'Reccomend Goods / Service Purchase (default).',
                'default' => '01',
                'desc_tip' => true,
                'options' => array(
                    '01' => 'Goods / Service Purchase',
                ),
            ),*/
            'jsLogging' => array(
                'title' => 'Enable console log? <small><em>Default:Off</em></small>',
                'label' => 'Console.log events',
                'type' => 'checkbox',
                'class' => 'wppd-ui-toggle',
                'description' => 'Only if requested to be activated by Nochex and private access is enabled should this be checked.',
                'default' => 'no',
                'desc_tip' => true,
            ),
            'serversidedebug' => array(
                'title' => 'Enable server side log? <small><em>Default:Off</em></small>',
                'label' => 'Debug log',
                'type' => 'checkbox',
                'class' => 'wppd-ui-toggle',
                'description' => 'Only if requested to be activated by Nochex and private access is enabled should this be checked.',
                'default' => 'no',
                'desc_tip' => true,
            ),
            'logLevels' => array(
                'title' => 'Logging level inclusion',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select-nostd',
                'default' => array(
                    'emergency',
                    'critical',
                    'error',
                    'warning',
                ),
                'options' => array(
                    'critical'=> 'Critical',
                    'debug' => 'Debugging',
                    'emergency' => 'Emergency',
                    'error' => 'Error',
                    'info' => 'Information',
                    'warning' => 'Warning'
                )
            ),
        )
    ),
    /*'status' => array(
        'title'=>'Card Payments Plugin Status',
        'description'=>'<p>Debugging or sense check there are no <strong>critical</strong> <span style="color:#a00;"><span class="dashicons dashicons-warning"></span> Error(s)</span> before allowing for public processing.</p><p><strong>Minor</strong> <span style="color:#ffaf20;"><span class="dashicons dashicons-warning"></span> Warning(s)</span> will allow payment processing to continue.</p>',
        'body' => 'showStatus'
    ),*/
    'FAQs' => array(
        'title'=>'FAQs',
        'description'=>'<p>FAQs</p>',
        'body' => 'showFaqs'
    )
);