<?php

    require_once "simple_html_dom.php";

    class Parser
    {
        protected function _log($message)
        {
            waLog::log($message, (new \ReflectionClass(static::class))->getShortName() . '.log');
            echo $message . "\n";
        }

        protected function get($url)
        {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 50);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $content = curl_exec($ch);

                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                return array(
                    'content' => $content,
                    'status' => $status
                );
            }

            return file_get_contents($url);
        }

        protected function getDataFromUrl($url)
        {
            for ($i = 0; $i <= 5; $i++){
                $response = $this->get($url,false);
                if ($response['status'] != 200) {
                    $response = $this->get($url,false);
                } else {
                    break;
                }
            }

            if ($response['status'] != 200) {
                $this->_log("url: ".$url." (is not reachable)\n");
            }

            $content = preg_replace('#<head>(.*?)</head>#is', '', $response['content']);
            $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);

            return [
                'content' => $content,
                'status' => $response['status']
            ];
        }

        protected function getHtml($url)
        {
            $data = $this->getDataFromUrl($url);

            if ($data['status'] != 200) {
                $this->_log("{$url}:Cannot receive data.");
            }

            $html = str_get_html($data['content']);

            if (empty($html)) {
                $this->_log( "Получение данных: шибка при получении DOM дерева. ({$url})");
                return [];
            }

            return $html;
        }

        protected function getBody($content)
        {
            if (is_null($content) || !$content) return false;

            $html = str_get_html($content);
            $body = $html->find('body',0)->innertext ?: '';

            return $body;
        }
    }