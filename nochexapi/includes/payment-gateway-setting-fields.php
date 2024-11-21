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
    'FAQs' => array(
        'title'=>'FAQs',
        'description'=>'<p>FAQs</p>',
        'body' => 'showFaqs'
    )
);