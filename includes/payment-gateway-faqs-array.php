<?php
use Nochexapi\WC_Nochexapi_Constants AS Nochexapi_CONSTANTS;
return [
    [ 
        'question' => "Can caching affect the gateway processing?",
        'answer'   => "<p>Sometimes extreme or unoptimized caching setups can affect the gateway and cause issues with processing. For best practices, and to avoid any potential issue with the Nochex plugin, we highly recommend that you exclude our plugin from all JS (JavaScript) deferrals or delaying systems and set the checkout page to be uncacheable. If you require support doing this, we suggest contacting your caching plugins support.</p>",
    ],
    [
        'question' => "How do I get my ID & Access Token?",
        'answer'   => "<p>You will be supplied with your Entity id and Access token once your Nochex account has been setup from our Nochex Support team. In the meantime, you can try out our plugin by changing your Current Endpoint mode to Test</p>",
    ],
    [
        'question' => "I can’t refund a user from the Woocommerece order page?",
        'answer'   => "<p>Unfortunately, we do not offer this functionality at this moment in time, you will need to login to your Nochex account to process a Refund.</p>",
    ],
    [
        'question' => "A customer is saying they paid, but no order has come through?",
        'answer'   => "<p>On an extremely rare occasion that you may find a customer stating they've paid, but you can't find an order in your order dashboard - we would suggest that you log into your Nochex account and search by billing name / Email address associated with your order.</p>",
    ],
    [
        'question' => "Have more questions, or require additional support?",
        'answer'   => "<p>Mail us to <a href=\"mailto:support@nochex.com\">support@nochex.com</a></p>",
    ],
];
