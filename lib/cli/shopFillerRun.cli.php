<?php

    class shopFillerRunCli extends waCliController
    {

        public function execute()
        {
            $plugin = new shopFillerPlugin(array('app_id' => 'shop','id' => 'filler'));

            $plugin_enabled = $plugin->getSettingPlugin('status_plugin');

            if ($plugin_enabled) {
                $plugin->run();
            } else {
                waLog::log("Плагин не активен.", 'shopFiller.log');
            }
        }

    }