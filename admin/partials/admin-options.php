 <style type="text/css">
/**
 * Checkbox Toggle UI
 */
input[type="checkbox"].wppd-ui-toggle {
    -webkit-appearance: none; 
    -moz-appearance: none;
    appearance: none;

    -webkit-tap-highlight-color: transparent;

    width: auto;
    height: auto;
    vertical-align: middle;
    position: relative;
    border: 0;
    outline: 0;
    cursor: pointer;
    margin: 0 4px;
    background: none;
    box-shadow: none;
}
input[type="checkbox"].wppd-ui-toggle:focus {
    box-shadow: none;
}
input[type="checkbox"].wppd-ui-toggle:after {
    content: '';
    font-size: 8px;
    font-weight: 400;
    line-height: 18px;
    text-indent: -14px;
    color: #ffffff;
    width: 36px;
    height: 18px;
    display: inline-block;
    background-color: #a7aaad;
    border-radius: 72px;
    box-shadow: 0 0 12px rgb(0 0 0 / 15%) inset;
}
input[type="checkbox"].wppd-ui-toggle:before {
    content: '';
    width: 14px;
    height: 14px;
    display: block;
    position: absolute;
    top: 2px;
    left: 2px;
    margin: 0;
    border-radius: 50%;
    background-color: #ffffff;
}
input[type="checkbox"].wppd-ui-toggle:checked:before {
    left: 20px;
    margin: 0;
    background-color: #ffffff;
}
input[type="checkbox"].wppd-ui-toggle,
input[type="checkbox"].wppd-ui-toggle:before,
input[type="checkbox"].wppd-ui-toggle:after,
input[type="checkbox"].wppd-ui-toggle:checked:before,
input[type="checkbox"].wppd-ui-toggle:checked:after {
    transition: ease .15s;
}
input[type="checkbox"].wppd-ui-toggle:checked:after {
    content: 'ON';
    background-color: #2271b1;
}
    table.wc_status_table tbody {font-weight:100!important; font-size: smaller;}
    table.wc_status_table code {font-weight:100!important; font-size: smaller!important;}
    table.wc_status_table tbody tr td {vertical-align: middle;}
    table.wc_status_table tbody tr td button.tp-admin-ajax {font-size:unset;line-height:unset;padding:5px;}
    td mark.warning {color: #ffaf20;}
</style>
<div>
    <?php
        $showSaveBtn=false;
        $htmlSubMenu='<ul id="settings-sections" class="subsubsub hide-if-no-js">'."\n";
        $htmlFormData='';
        $htmlTitle='';
        $htmlDescription='';
        $htmlBody='';
        $settingFields = $this->settings_fields(false);
        if(is_array($settingFields)){
            $lastSetting = count($settingFields);
            $qsArray=[];
            wp_parse_str( $_SERVER['QUERY_STRING'], $qsArray );
            if(!isset($qsArray['opt'])){
                $sectionId='general';
            } else {
                $sectionId=$qsArray['opt'];
            }
            foreach( $settingFields as $section => $data ) {
                $lastSetting--;
                $current='';
                if(isset($qsArray['opt'])){
                    if($qsArray['opt'] === $section){
                        $current=' current';
                    }
                } else {
                    if($section==='general'){
                        $current=' current';
                    }
                }
                if($section==='general'){
                    $href='';
                } else {
                    $href='&opt='.$section;
                }
                $htmlSubMenu .= '<li><a class="tab'.$current.'" href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section='. $this->id . $href . '">' . ucwords($section) . '</a>'; if($lastSetting > 0){$htmlSubMenu .=' | ';} $htmlSubMenu .= '</li>' . "\n";
                $display='none';
                if($section===$sectionId){
                    $display='block';
                }
                if(isset($data['fields'])){
                    if(count($data['fields'])>0){
                        if($display==='block'){
                            $showSaveBtn=true;
                        }
                        $htmlFormData .= '<table class="form-table" style="display:'.$display.';">'."\n";
                        $htmlFormData .= $this->generate_settings_html( $data['fields'], false );
                        $htmlFormData .= '</table>'."\n";
                    }
                }
            }
            if(isset($settingFields[$sectionId]['title'])){
                $htmlTitle=$settingFields[$sectionId]['title'];
            } else {
                //
            }
            if(isset($settingFields[$sectionId]['description'])){
                $htmlDescription=$settingFields[$sectionId]['description'];
            } else {
                //
            }
            if(isset($settingFields[$sectionId]['body'])){
                $htmlBody=$settingFields[$sectionId]['body'];
            } else {
                $htmlBody='';
            }
        }
        $htmlSubMenu .= '</ul>' . "\n";
        $htmlSubMenu .= '<br class="clear" />' . "\n";
        if($showSaveBtn===false){
            $htmlSubMenu .= '<style type="text/css">form#mainform p.submit {display:none;}</style>';
        }
    ?>
    <?php echo $htmlSubMenu; ?>
    <table style="width:100%;">
        <tr>
            <td style="text-align:left; padding-right:2em; width:75%;">
                <h3><?php echo $htmlTitle; ?></h3>
                <?php echo $htmlDescription; ?>
            </td>
            <td style="text-align:right; width:25%;">
                <img src="<?php echo plugin_dir_url( dirname( __DIR__ ) ).'assets/img/'.'nochexapilogo.png'; ?>" alt="Nochex" style="height:58px;">
            </td>
        </tr>
    </table>
    <?php echo $htmlFormData; ?>
    <?php
        if($htmlBody==='showStatus') {
            echo '<div id="statusTablesContainer">'."\n";
            echo $this->generateStatusArray( true );
            echo '</div>'."\n";
        } else if($htmlBody==='showFaqs'){
            $faqs =  $this->getFAQsArray();
        ?>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
            <style>
	        /* Custom style */
            .accordion-button::after {
                background-image: url("data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='%23333' xmlns='http://www.w3.org/2000/svg'%3e%3cpath fill-rule='evenodd' d='M8 0a1 1 0 0 1 1 1v6h6a1 1 0 1 1 0 2H9v6a1 1 0 1 1-2 0V9H1a1 1 0 0 1 0-2h6V1a1 1 0 0 1 1-1z' clip-rule='evenodd'/%3e%3c/svg%3e");
                transform: scale(.7) !important;
            }
            .accordion-button:not(.collapsed)::after {
                background-image: url("data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='%23333' xmlns='http://www.w3.org/2000/svg'%3e%3cpath fill-rule='evenodd' d='M0 8a1 1 0 0 1 1-1h14a1 1 0 1 1 0 2H1a1 1 0 0 1-1-1z' clip-rule='evenodd'/%3e%3c/svg%3e");
            }
            </style>
            <div class="m-4">
                <div class="accordion" id="faqAccordion">
                    <?php foreach( $faqs AS $k => $faq ):?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading<?php echo $k;?>">
                            <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $k;?>"><?php echo ($k+1);?>. <?php echo $faq['question'];?></button>									
                        </h2>
                        <div id="collapse<?php echo $k;?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="card-body">
                                <p><?php echo $faq['answer'];?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach;?>
                </div>
            </div>
        <?php
        }else if($htmlBody==='showLogs') {
            echo '<style type="text/css">form#mainform p.submit {display:none;}</style>';
            echo '<div id="debuggingLogsContainer">'."\n";
            echo $this->generateDebuggingLogsHTML();
            echo '</div>'."\n";
        } else {
            echo $htmlBody;
        }
        if($sectionId === 'status') {
    ?>
    <!--js-->
    <script type="text/javascript">
        jQuery(function(){
            jQuery('body').on('click', '.tp-admin-ajax', function(e){
                e.preventDefault();
                var action = jQuery(this).data('pl_action');
                return wp.ajax.post(action,{})
                .then(function(response) {
                    console.log(response);
                    if(response.valid==true){
                        if(response.hasOwnProperty('containerId')){
                            var el=document.getElementById(response.containerId);
                            if(response.hasOwnProperty('html')){
                                el.innerHTML=response.html;
                            }
                        }
                    }
                });
            });
            var param = {};
            jQuery('body').on('click', '.looping-ajax-call', function(e){
                e.preventDefault();
                var action = jQuery(this).data('pl_action');
                return wp.ajax.post(action, param)
                .then(function(response) {
                    console.log(response);
                    if(response.valid==true){
                        if(response.hasOwnProperty('containerId')){
                            var el=document.getElementById(response.containerId);
                            if(response.hasOwnProperty('html')){
                                el.innerHTML=response.html;
                            }
                            if(response.hasOwnProperty('nextcall')){
                                param  = {'nextpage': response.nextpage, 'paymentid': response.paymentid};
                                jQuery('.looping-ajax-call').trigger('click');
                            }
                        }
                    }
                });
            });
        });
    </script>
    <!--/js-->
    <pre style="display:block;">
    <?php
            $runTestCode = false;
    ?>
    </pre>
    
    <?php if($runTestCode === true) { ?><pre>
    <?php
        //in here for all test code before breaking admin!!
        //
        //
        //
        $fullCode = 0;
        
        if($fullCode === 1){
            
        } else {
            
        }
        
        //end code block for test
        //
        //
    ?>
    </pre><?php } ?>
    
    <?php
        }
    ?>
</div>
<br class="clear" />