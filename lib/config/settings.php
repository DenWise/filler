<?php

    $cron = '0 1 * * * php %s/cli.php shop fillerRun';
    $cronTask = sprintf($cron, wa()->getConfig()->getRootPath());

return array(
    'status_plugin'  => array(
        'title'      => _wp('Status plugin'),
        'options' => array(
            0 => _wp('Disabled'),
            1 => _wp('Enabled'),
        ),
        'description'  => "
        <div style='margin-top: 30px;'>
            <p style='font-size: 18px; color: black; margin-bottom: 6px;'>Cron - задания:</p>
            <p style='font-size: 14px; background: black; color: white; padding: 5px; margin-bottom: 2px;'>{$cronTask}</p>
        </div>",
        'value' => 1,
        'control_type'=> waHtmlControl::SELECT,
    )
);